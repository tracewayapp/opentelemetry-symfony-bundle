<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Psr;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Traceway\OpenTelemetryBundle\HttpClient\HostExclusionTrait;
use Traceway\OpenTelemetryBundle\Instrumentation\MeterAwareTrait;
use Traceway\OpenTelemetryBundle\Metrics\DurationBoundaries;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;
use Traceway\OpenTelemetryBundle\Util\UrlParts;

/**
 * http.client.* metrics for PSR-7 requests, shared by the PSR-18 decorator and
 * the Guzzle middleware. Same instruments, units, buckets and attributes as
 * {@see \Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient}.
 */
final class RequestMeter
{
    use HostExclusionTrait;
    use MeterAwareTrait;

    /** @var ContextKeyInterface<bool>|null */
    private static ?ContextKeyInterface $inFlightKey = null;

    private ?HistogramInterface $duration = null;
    private ?HistogramInterface $requestBodySize = null;
    private ?HistogramInterface $responseBodySize = null;

    /**
     * @param string[] $excludedHosts
     */
    public function __construct(
        private readonly string $meterName = 'opentelemetry-symfony',
        array $excludedHosts = [],
    ) {
        $this->excludedHosts = array_map('strtolower', array_values($excludedHosts));
    }

    public function shouldMeter(RequestInterface $request): bool
    {
        return !$this->isExcluded((string) $request->getUri())
            && true !== Context::getCurrent()->get(self::inFlightKey());
    }

    /** Marks a context so a client nested inside the measured one does not measure the same request again. */
    public function markInFlight(ContextInterface $context): ContextInterface
    {
        return $context->with(self::inFlightKey(), true);
    }

    /**
     * @return array{start: int|float, attributes: array<non-empty-string, string|int>, requestBodySize: ?int}
     */
    public function start(RequestInterface $request): array
    {
        $uri = $request->getUri();
        $attributes = [HttpAttributes::HTTP_REQUEST_METHOD => HttpMethodResolver::normalize($request->getMethod())];

        $host = UrlParts::host(['host' => $uri->getHost()]);
        if (null !== $host) {
            $attributes[ServerAttributes::SERVER_ADDRESS] = $host;

            $parsed = ['scheme' => $uri->getScheme()];
            if (null !== $uri->getPort()) {
                $parsed['port'] = $uri->getPort();
            }
            $port = UrlParts::port($parsed);
            if (null !== $port) {
                $attributes[ServerAttributes::SERVER_PORT] = $port;
            }
        }

        if ('' !== $uri->getScheme()) {
            $attributes[UrlAttributes::URL_SCHEME] = $uri->getScheme();
        }

        $requestBodySize = null;
        $contentLength = $request->getHeaderLine('Content-Length');
        if (is_numeric($contentLength)) {
            $requestBodySize = (int) $contentLength;
        } elseif (null !== ($size = $request->getBody()->getSize()) && $size > 0) {
            $requestBodySize = $size;
        }

        return ['start' => hrtime(true), 'attributes' => $attributes, 'requestBodySize' => $requestBodySize];
    }

    /**
     * @param array{start: int|float, attributes: array<non-empty-string, string|int>, requestBodySize: ?int} $state
     */
    public function recordResponse(array $state, ResponseInterface $response): void
    {
        try {
            $attributes = $state['attributes'];
            $statusCode = $response->getStatusCode();
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $statusCode;
            if ($statusCode >= 400) {
                $attributes[ErrorAttributes::ERROR_TYPE] = (string) $statusCode;
            }

            $version = ProtocolVersion::fromServerProtocol($response->getProtocolVersion());
            if (null !== $version) {
                $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = $version;
            }

            $this->record($state, $attributes);

            $contentLength = $response->getHeaderLine('Content-Length');
            if (is_numeric($contentLength)) {
                $this->responseBodySizeHistogram()->record((int) $contentLength, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param array{start: int|float, attributes: array<non-empty-string, string|int>, requestBodySize: ?int} $state
     */
    public function recordFailure(array $state, \Throwable $e): void
    {
        try {
            $attributes = $state['attributes'];
            if (method_exists($e, 'getResponse') && ($response = $e->getResponse()) instanceof ResponseInterface) {
                $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $response->getStatusCode();
            }
            $attributes[ErrorAttributes::ERROR_TYPE] = ErrorTypeResolver::resolve($e);

            $this->record($state, $attributes);
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->resetMeter();
        $this->resetHostExclusion();
        $this->duration = null;
        $this->requestBodySize = null;
        $this->responseBodySize = null;
    }

    /** @return ContextKeyInterface<bool> */
    public static function inFlightKey(): ContextKeyInterface
    {
        return self::$inFlightKey ??= Context::createKey(self::class);
    }

    /**
     * @param array{start: int|float, attributes: array<non-empty-string, string|int>, requestBodySize: ?int} $state
     * @param array<non-empty-string, string|int>                                                             $attributes
     */
    private function record(array $state, array $attributes): void
    {
        $this->durationHistogram()->record((hrtime(true) - $state['start']) / 1_000_000_000, $attributes);

        if (null !== $state['requestBodySize']) {
            $this->requestBodySizeHistogram()->record($state['requestBodySize'], $attributes);
        }
    }

    private function durationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->getMeter()->createHistogram(
            'http.client.request.duration',
            's',
            'Duration of outbound HTTP client requests',
            ['ExplicitBucketBoundaries' => DurationBoundaries::SECONDS],
        );
    }

    private function requestBodySizeHistogram(): HistogramInterface
    {
        return $this->requestBodySize ??= $this->getMeter()->createHistogram('http.client.request.body.size', 'By', 'Size of HTTP client request bodies');
    }

    private function responseBodySizeHistogram(): HistogramInterface
    {
        return $this->responseBodySize ??= $this->getMeter()->createHistogram('http.client.response.body.size', 'By', 'Size of HTTP client response bodies');
    }
}
