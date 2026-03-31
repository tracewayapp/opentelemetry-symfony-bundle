<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

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
        $host = \is_array($parsedUrl) ? ($parsedUrl['host'] ?? 'unknown') : 'unknown';

        $spanBuilder = $this->getTracer()
            ->spanBuilder(sprintf('%s %s', $method, $host))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(UrlAttributes::URL_FULL, $url)
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $host);

        if (\is_array($parsedUrl)) {
            if (isset($parsedUrl['port'])) {
                $spanBuilder->setAttribute(ServerAttributes::SERVER_PORT, $parsedUrl['port']);
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
            $response = $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            $scope->detach();
            $this->inFlight = false;

            throw $e;
        }

        $scope->detach();
        $this->inFlight = false;

        return new TracedResponse($response, $span);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $underlyingResponses = [];

        /** @var ResponseInterface $response */
        foreach ($responses as $response) {
            $underlyingResponses[] = $response instanceof TracedResponse
                ? $response->getInnerResponse()
                : $response;
        }

        return $this->client->stream($underlyingResponses, $timeout);
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
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
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

    /**
     * Auto-detect calls to the OTLP exporter endpoint to prevent
     * instrumentation loops when the exporter resolves to this client.
     */
    private function isOtlpEndpoint(string $url): bool
    {
        $endpoint = $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']
            ?? $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT']
            ?? getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

        if (!\is_string($endpoint) || '' === $endpoint) {
            return false;
        }

        return str_starts_with($url, $endpoint);
    }
}
