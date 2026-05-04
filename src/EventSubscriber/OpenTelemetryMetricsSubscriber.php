<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Emits OpenTelemetry metrics for Symfony HTTP server requests.
 *
 * Sibling of {@see OpenTelemetrySubscriber}. Only main requests are measured
 * (sub-requests are already included in the main request duration). Each
 * subscriber owns its lazy {@see MeterInterface}, caches instruments, and
 * implements {@see ResetInterface} so long-running processes (PHP-FPM
 * request recycling, Swoole, etc.) do not leak state.
 *
 * Metrics (OTel HTTP server metrics semconv):
 *   - http.server.request.duration       (Histogram, s)        [Stable]
 *   - http.server.active_requests        (UpDownCounter, {request}) [Development]
 *   - http.server.request.body.size      (Histogram, By)       [Development]
 *   - http.server.response.body.size     (Histogram, By)       [Development]
 *
 * Attributes:
 *   - http.request.method            (required) [Stable]
 *   - url.scheme                     (required) [Stable]
 *   - http.route                     (conditional, if matched) [Stable]
 *   - http.response.status_code      (conditional, on response) [Stable]
 *   - server.address                 [Stable]
 *   - server.port                    [Stable]
 *   - error.type                     (conditional, on failure) [Stable]
 */
final class OpenTelemetryMetricsSubscriber implements EventSubscriberInterface, ResetInterface
{
    public const DURATION_BUCKET_BOUNDARIES = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    /** @var list<string> */
    private readonly array $excludedPaths;

    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;
    private ?UpDownCounterInterface $activeRequests = null;
    private ?HistogramInterface $requestBodySize = null;
    private ?HistogramInterface $responseBodySize = null;

    /** @var \WeakMap<Request, array{start: int|float, active_counted: bool, route?: string, exception?: \Throwable}> */
    private \WeakMap $requestData;

    /**
     * @param string[] $excludedPaths URL path prefixes to skip (must start with /)
     */
    public function __construct(
        private readonly string $meterName = 'opentelemetry-symfony',
        array $excludedPaths = [],
    ) {
        $this->excludedPaths = array_values($excludedPaths);
        $this->requestData = new \WeakMap();
    }

    /**
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onRequest', 256],
                ['onRoute', 30],
            ],
            KernelEvents::EXCEPTION => ['onException', 0],
            KernelEvents::RESPONSE => ['onResponse', -256],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', -512],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isExcluded($request)) {
            return;
        }

        $activeCounted = false;
        try {
            $this->getActiveRequestsCounter()->add(1, $this->baseAttributes($request));
            $activeCounted = true;
        } catch (\Throwable) {
        }

        $this->requestData[$request] = [
            'start' => hrtime(true),
            'active_counted' => $activeCounted,
        ];
    }

    /**
     * Once the router has resolved, capture the URL path template so
     * the emitted metrics group endpoints correctly
     * (e.g. /api/items/{id} instead of /api/items/5).
     * Replicates the substitution logic used by {@see OpenTelemetrySubscriber}.
     */
    public function onRoute(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $data = $this->requestData[$request] ?? null;
        if (null === $data) {
            return;
        }

        $routeParams = $request->attributes->get('_route_params', []);
        $path = $request->getPathInfo();

        if (\is_array($routeParams)) {
            $params = array_filter(
                $routeParams,
                static fn (mixed $v): bool => (\is_string($v) || \is_int($v)) && '' !== (string) $v,
            );

            uasort($params, static fn (mixed $a, mixed $b): int => \strlen((string) $b) <=> \strlen((string) $a));

            foreach ($params as $name => $value) {
                $path = str_replace((string) $value, '{' . $name . '}', $path);
            }
        }

        $data['route'] = $path;
        $this->requestData[$request] = $data;
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $data = $this->requestData[$request] ?? null;
        if (null === $data) {
            return;
        }

        $data['exception'] = $event->getThrowable();
        $this->requestData[$request] = $data;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $data = $this->requestData[$request] ?? null;
        if (null === $data) {
            return;
        }

        try {
            $attributes = $this->baseAttributes($request);
            if (isset($data['route'])) {
                $attributes[HttpAttributes::HTTP_ROUTE] = $data['route'];
            }

            $response = $event->getResponse();
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $response->getStatusCode();

            if (isset($data['exception'])) {
                $attributes[ErrorAttributes::ERROR_TYPE] = ErrorTypeResolver::resolve($data['exception']);
            }

            $durationSeconds = (hrtime(true) - $data['start']) / 1_000_000_000;
            $this->getDurationHistogram()->record($durationSeconds, $attributes);

            $requestBodySize = $request->headers->get('Content-Length');
            if (null !== $requestBodySize && ctype_digit($requestBodySize)) {
                $this->getRequestBodySizeHistogram()->record((int) $requestBodySize, $attributes);
            }

            $responseBodySize = $response->headers->get('Content-Length');
            if (null !== $responseBodySize && ctype_digit($responseBodySize)) {
                $this->getResponseBodySizeHistogram()->record((int) $responseBodySize, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $data = $this->requestData[$request] ?? null;
        if (null === $data) {
            return;
        }

        if ($data['active_counted']) {
            try {
                $this->getActiveRequestsCounter()->add(-1, $this->baseAttributes($request));
            } catch (\Throwable) {
            }
        }

        unset($this->requestData[$request]);
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
        $this->activeRequests = null;
        $this->requestBodySize = null;
        $this->responseBodySize = null;
        $this->requestData = new \WeakMap();
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    private function baseAttributes(Request $request): array
    {
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            UrlAttributes::URL_SCHEME => $request->getScheme(),
            ServerAttributes::SERVER_ADDRESS => $request->getHost(),
        ];

        $port = $request->getPort();
        if (null !== $port) {
            $attributes[ServerAttributes::SERVER_PORT] = $port;
        }

        return $attributes;
    }

    private function isExcluded(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach ($this->excludedPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
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
            'Duration of HTTP server requests',
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKET_BOUNDARIES],
        );
    }

    private function getActiveRequestsCounter(): UpDownCounterInterface
    {
        return $this->activeRequests ??= $this->getMeter()->createUpDownCounter(
            $this->metricName('active_requests'),
            '{request}',
            'Number of active HTTP server requests',
        );
    }

    private function getRequestBodySizeHistogram(): HistogramInterface
    {
        return $this->requestBodySize ??= $this->getMeter()->createHistogram(
            $this->metricName('request_body_size'),
            'By',
            'Size of HTTP server request bodies',
        );
    }

    private function getResponseBodySizeHistogram(): HistogramInterface
    {
        return $this->responseBodySize ??= $this->getMeter()->createHistogram(
            $this->metricName('response_body_size'),
            'By',
            'Size of HTTP server response bodies',
        );
    }

    /**
     * Central metric name resolution. Ready for OTEL_SEMCONV_STABILITY_OPT_IN
     * dual-emit if the spec introduces alternative names.
     *
     * @param 'duration'|'active_requests'|'request_body_size'|'response_body_size' $key
     */
    private function metricName(string $key): string
    {
        return match ($key) {
            'duration' => 'http.server.request.duration',
            'active_requests' => 'http.server.active_requests',
            'request_body_size' => 'http.server.request.body.size',
            'response_body_size' => 'http.server.response.body.size',
        };
    }

}
