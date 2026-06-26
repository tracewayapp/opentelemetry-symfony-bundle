<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

/**
 * Decorates any Symfony HttpClient to create CLIENT spans for outgoing requests
 * and propagate W3C Trace Context into outgoing headers.
 *
 * Includes a re-entrance guard to prevent instrumentation loops: if an outgoing
 * HTTP call is triggered while we're already inside a traced call (e.g. the OTLP
 * exporter sending spans via this client), the nested call is passed through
 * without creating an additional span.
 */
final class TraceableHttpClient implements HttpClientInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;
    private ?string $otlpEndpoint = null;
    private bool $otlpEndpointResolved = false;

    /** Prevents recursive instrumentation when the exporter uses this client. */
    private bool $inFlight = false;

    /** @var string[] */
    private readonly array $excludedHosts;

    /**
     * @param string[] $excludedHosts Hostnames to skip tracing for (e.g. OTLP collector)
     */
    public function __construct(
        private HttpClientInterface $client,
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedHosts = [],
    ) {
        $this->excludedHosts = array_map('strtolower', array_values($excludedHosts));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (!$this->isEnabled() || $this->inFlight || $this->isExcluded($url)) {
            return $this->client->request($method, $url, $options);
        }

        $parsedUrl = parse_url($url);
        $normalizedMethod = HttpMethodResolver::normalize($method);

        $spanBuilder = $this->getTracer()
            ->spanBuilder(HttpMethodResolver::spanNameMethod($method))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $normalizedMethod)
            ->setAttribute(UrlAttributes::URL_FULL, UrlSanitizer::sanitizeUrl($url));

        if ($normalizedMethod !== $method) {
            $spanBuilder->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $method);
        }

        if (\is_array($parsedUrl)) {
            if (isset($parsedUrl['host'])) {
                $spanBuilder->setAttribute(ServerAttributes::SERVER_ADDRESS, $parsedUrl['host']);

                $port = $parsedUrl['port'] ?? match (strtolower($parsedUrl['scheme'] ?? '')) {
                    'https' => 443,
                    'http' => 80,
                    default => null,
                };
                if (null !== $port) {
                    $spanBuilder->setAttribute(ServerAttributes::SERVER_PORT, (int) $port);
                }
            }
            if (isset($parsedUrl['path'])) {
                $spanBuilder->setAttribute(UrlAttributes::URL_PATH, $parsedUrl['path']);
            }
            if (isset($parsedUrl['scheme'])) {
                $spanBuilder->setAttribute(UrlAttributes::URL_SCHEME, $parsedUrl['scheme']);
            }
        }

        $parent = Context::getCurrent();
        $span = $spanBuilder->setParent($parent)->startSpan();
        $context = $span->storeInContext($parent);

        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }
        Globals::propagator()->inject($options['headers'], HeadersPropagationSetter::instance(), $context);

        $scope = $context->activate();
        $this->inFlight = true;

        try {
            try {
                $response = $this->client->request($method, $url, $options);
            } catch (\Throwable $e) {
                $span->recordException($e);
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
                $span->end();

                throw $e;
            }
        } finally {
            $scope->detach();
            $this->inFlight = false;
        }

        return new TracedResponse($response, $span, \is_array($parsedUrl) && isset($parsedUrl['host']));
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $tracedMap = new \SplObjectStorage();
        $underlyingResponses = [];

        foreach ($responses as $response) {
            if ($response instanceof TracedResponse) {
                $inner = $response->getInnerResponse();
                $tracedMap[$inner] = $response;
                $underlyingResponses[] = $inner;
            } else {
                $tracedMap[$response] = $response;
                $underlyingResponses[] = $response;
            }
        }

        return new ResponseStream((function () use ($tracedMap, $underlyingResponses, $timeout) {
            foreach ($this->client->stream($underlyingResponses, $timeout) as $response => $chunk) {
                $traced = $tracedMap[$response];
                if ($traced instanceof TracedResponse) {
                    try {
                        if ($chunk->isLast()) {
                            $traced->finalizeFromStream();
                        }
                    } catch (\Throwable $e) {
                        $traced->finalizeStreamError($e);
                    }
                }
                yield $traced => $chunk;
            }
        })());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;
        $this->otlpEndpoint = null;
        $this->otlpEndpointResolved = false;
        $this->inFlight = false;

        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }

    private function isExcluded(string $url): bool
    {
        if ([] === $this->excludedHosts) {
            return $this->isOtlpEndpoint($url);
        }

        $host = strtolower((string) (parse_url($url, \PHP_URL_HOST) ?? ''));

        foreach ($this->excludedHosts as $excluded) {
            if ($host === $excluded) {
                return true;
            }
        }

        return $this->isOtlpEndpoint($url);
    }

    private function isOtlpEndpoint(string $url): bool
    {
        if (!$this->otlpEndpointResolved) {
            $endpoint = $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']
                ?? $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT']
                ?? getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

            $this->otlpEndpoint = (\is_string($endpoint) && '' !== $endpoint) ? $endpoint : null;
            $this->otlpEndpointResolved = true;
        }

        return null !== $this->otlpEndpoint && str_starts_with($url, $this->otlpEndpoint);
    }
}
