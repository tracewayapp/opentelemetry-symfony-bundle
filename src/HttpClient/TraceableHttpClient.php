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
 */
final class TraceableHttpClient implements HttpClientInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;

    public function __construct(
        private HttpClientInterface $client,
        private readonly string $tracerName = 'opentelemetry-symfony',
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $parsedUrl = parse_url($url);
        $host = \is_array($parsedUrl) ? ($parsedUrl['host'] ?? 'unknown') : 'unknown';

        $spanBuilder = $this->getTracer()
            ->spanBuilder(sprintf('%s %s', $method, $host))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(UrlAttributes::URL_FULL, $url)
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $host);

        if (\is_array($parsedUrl) && isset($parsedUrl['port'])) {
            $spanBuilder->setAttribute(ServerAttributes::SERVER_PORT, $parsedUrl['port']);
        }

        $parent = Context::getCurrent();
        $span = $spanBuilder->setParent($parent)->startSpan();
        $context = $span->storeInContext($parent);

        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }
        Globals::propagator()->inject($options['headers'], HeadersPropagationSetter::instance(), $context);

        $scope = $context->activate();

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            $scope->detach();

            throw $e;
        }

        $scope->detach();

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
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }
}
