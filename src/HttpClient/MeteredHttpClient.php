<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates any Symfony HttpClient to emit OpenTelemetry metrics for
 * outgoing requests, sibling of {@see TraceableHttpClient} on the trace side.
 *
 * Includes the same re-entrance guard as the trace decorator: if an outgoing
 * HTTP call is triggered while already inside a measured call (e.g. the OTLP
 * exporter sending metrics via this client), the nested call passes through
 * without recording.
 *
 * Metrics (OTel HTTP client metrics semconv):
 *   - http.client.request.duration       (Histogram, s)  [Stable]
 *   - http.client.request.body.size      (Histogram, By) [Development]
 *   - http.client.response.body.size     (Histogram, By) [Development]
 *
 * Attributes:
 *   - http.request.method        (required) [Stable]
 *   - server.address             (required) [Stable]
 *   - server.port                (required) [Stable]
 *   - url.scheme                 [Stable]
 *   - http.response.status_code  (on response) [Stable]
 *   - error.type                 (on failure) [Stable]
 */
final class MeteredHttpClient implements HttpClientInterface, ResetInterface
{
    public const DURATION_BUCKET_BOUNDARIES = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;
    private ?HistogramInterface $requestBodySize = null;
    private ?HistogramInterface $responseBodySize = null;

    private ?string $otlpEndpoint = null;
    private bool $otlpEndpointResolved = false;

    /** Prevents recursive instrumentation when the exporter uses this client. */
    private bool $inFlight = false;

    /** @var list<string> */
    private readonly array $excludedHosts;

    /**
     * @param string[] $excludedHosts Hostnames to skip metrics for (e.g. OTLP collector)
     */
    public function __construct(
        private HttpClientInterface $client,
        private readonly string $meterName = 'opentelemetry-symfony',
        array $excludedHosts = [],
    ) {
        $this->excludedHosts = array_map('strtolower', array_values($excludedHosts));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->inFlight || $this->isExcluded($url)) {
            return $this->client->request($method, $url, $options);
        }

        $attributes = $this->requestAttributes($method, $url);
        $bodySize = $this->extractRequestBodySize($options);

        if (null !== $bodySize) {
            try {
                $this->getRequestBodySizeHistogram()->record($bodySize, $attributes);
            } catch (\Throwable) {
            }
        }

        $start = hrtime(true);
        $this->inFlight = true;

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            $this->recordFailure($start, $attributes, $e);

            throw $e;
        } finally {
            $this->inFlight = false;
        }

        return new MeteredResponse($response, $this, $start, $attributes);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $underlyingResponses = [];

        /** @var ResponseInterface $response */
        foreach ($responses as $response) {
            $underlyingResponses[] = $response instanceof MeteredResponse
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
        $this->meter = null;
        $this->duration = null;
        $this->requestBodySize = null;
        $this->responseBodySize = null;
        $this->otlpEndpoint = null;
        $this->otlpEndpointResolved = false;
        $this->inFlight = false;

        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    /**
     * @internal called by {@see MeteredResponse} when the response finalizes successfully
     *
     * @param array<non-empty-string, string|int> $attributes
     */
    public function recordResponse(int|float $start, array $attributes, int $statusCode, ?int $responseBodySize): void
    {
        try {
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $statusCode;

            $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
            $this->getDurationHistogram()->record($durationSeconds, $attributes);

            if (null !== $responseBodySize) {
                $this->getResponseBodySizeHistogram()->record($responseBodySize, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @internal called by {@see MeteredResponse} or by {@see self::request} when a request/response fails
     *
     * @param array<non-empty-string, string|int> $attributes
     */
    public function recordFailure(int|float $start, array $attributes, \Throwable $exception): void
    {
        try {
            $attributes[ErrorAttributes::ERROR_TYPE] = self::resolveErrorType($exception);

            $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
            $this->getDurationHistogram()->record($durationSeconds, $attributes);
        } catch (\Throwable) {
        }
    }

    private static function resolveErrorType(\Throwable $exception): string
    {
        $type = $exception::class;

        if (str_contains($type, '@anonymous')) {
            $type = get_parent_class($exception) ?: \Throwable::class;
        }

        return $type;
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    private function requestAttributes(string $method, string $url): array
    {
        $parsed = parse_url($url);
        $host = \is_array($parsed) ? ($parsed['host'] ?? 'unknown') : 'unknown';

        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $method,
            ServerAttributes::SERVER_ADDRESS => $host,
        ];

        if (\is_array($parsed)) {
            if (isset($parsed['port'])) {
                $attributes[ServerAttributes::SERVER_PORT] = (int) $parsed['port'];
            }
            if (isset($parsed['scheme'])) {
                $attributes[UrlAttributes::URL_SCHEME] = $parsed['scheme'];
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractRequestBodySize(array $options): ?int
    {
        if (isset($options['headers']) && \is_array($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                if (!\is_string($name) || 'content-length' !== strtolower($name)) {
                    continue;
                }
                $size = \is_array($value) ? ($value[0] ?? null) : $value;
                if (is_numeric($size)) {
                    return (int) $size;
                }
            }
        }

        if (isset($options['body']) && \is_string($options['body'])) {
            return \strlen($options['body']);
        }

        return null;
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

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter($this->meterName);
    }

    private function getDurationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->getMeter()->createHistogram(
            $this->metricName('duration'),
            's',
            'Duration of outbound HTTP client requests',
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKET_BOUNDARIES],
        );
    }

    private function getRequestBodySizeHistogram(): HistogramInterface
    {
        return $this->requestBodySize ??= $this->getMeter()->createHistogram(
            $this->metricName('request_body_size'),
            'By',
            'Size of HTTP client request bodies',
        );
    }

    private function getResponseBodySizeHistogram(): HistogramInterface
    {
        return $this->responseBodySize ??= $this->getMeter()->createHistogram(
            $this->metricName('response_body_size'),
            'By',
            'Size of HTTP client response bodies',
        );
    }

    /**
     * Central metric name resolution. Ready for OTEL_SEMCONV_STABILITY_OPT_IN
     * dual-emit once further spec revisions introduce alternative names.
     *
     * @param 'duration'|'request_body_size'|'response_body_size' $key
     */
    private function metricName(string $key): string
    {
        return match ($key) {
            'duration' => 'http.client.request.duration',
            'request_body_size' => 'http.client.request.body.size',
            'response_body_size' => 'http.client.response.body.size',
        };
    }
}
