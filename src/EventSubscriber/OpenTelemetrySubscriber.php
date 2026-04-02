<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Automatic HTTP request instrumentation for Symfony using OpenTelemetry.
 *
 * Creates a SERVER span per request with proper URL path templates,
 * semantic conventions, sub-request handling, and exception recording.
 */
final class OpenTelemetrySubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var string[] */
    private readonly array $excludedPaths;

    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    /** @var \WeakMap<Request, array{span?: SpanInterface, scope?: ScopeInterface, exception?: \Throwable}> */
    private \WeakMap $requestData;

    /**
     * @param string   $tracerName           Instrumentation library name
     * @param string[] $excludedPaths        URL path prefixes to skip (must start with /)
     * @param bool     $recordClientIp       Whether to record client.address
     * @param int      $errorStatusThreshold HTTP status codes >= this are marked as errors
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedPaths = [],
        private readonly bool $recordClientIp = true,
        private readonly int $errorStatusThreshold = 500,
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
            KernelEvents::FINISH_REQUEST => [
                ['onFinishRequestDetachScope', -256],
                ['onFinishRequestEndSpan', -256],
            ],
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $request = $event->getRequest();

        if ($this->isExcluded($request)) {
            return;
        }

        $tracer = $this->getTracer();

        $spanBuilder = $tracer
            ->spanBuilder(sprintf('HTTP %s', $request->getMethod()))
            ->setSpanKind($event->isMainRequest() ? SpanKind::KIND_SERVER : SpanKind::KIND_INTERNAL)
            ->setAttributes($this->requestAttributes($request));

        $parentContext = Context::getCurrent();

        if ($event->isMainRequest()) {
            $carrier = array_map(
                static fn (array $values): string => $values[0] ?? '',
                array_change_key_case($request->headers->all(), \CASE_LOWER),
            );
            $parentContext = Globals::propagator()->extract($carrier);

            $requestTime = $request->server->get('REQUEST_TIME_FLOAT');
            if (null !== $requestTime && is_numeric($requestTime)) {
                $spanBuilder->setStartTimestamp((int) ((float) $requestTime * 1_000_000_000));
            }
        }

        $span = $spanBuilder->setParent($parentContext)->startSpan();

        $distributedTraceId = $request->headers->get('traceway-trace-id');
        if (null !== $distributedTraceId && '' !== $distributedTraceId) {
            $span->setAttribute('traceway.distributed_trace_id', $distributedTraceId);
        }

        $scope = $span->storeInContext($parentContext)->activate();

        $this->requestData[$request] = ['span' => $span, 'scope' => $scope];
    }

    /**
     * Once the router has resolved, update the span name with a URL path template.
     *
     * Replaces resolved parameter values with {param} placeholders so backends
     * group endpoints correctly (e.g. /api/items/{id} instead of /api/items/5).
     * Longer values are replaced first to prevent substring collision.
     */
    public function onRoute(RequestEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $request = $event->getRequest();
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

        $method = $request->getMethod();
        $span->updateName(sprintf('%s %s', $method, $path));
        $span->setAttribute(HttpAttributes::HTTP_ROUTE, $path);
    }

    public function onException(ExceptionEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $span->recordException($event->getThrowable());
        $span->setStatus(StatusCode::STATUS_ERROR, $event->getThrowable()->getMessage());

        $data = $this->requestData[$event->getRequest()] ?? [];
        $data['exception'] = $event->getThrowable();
        $this->requestData[$event->getRequest()] = $data;
    }

    public function onResponse(ResponseEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $data = $this->requestData[$event->getRequest()] ?? [];
        $hadException = isset($data['exception']);
        unset($data['exception']);
        $this->requestData[$event->getRequest()] = $data;

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();
        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        $requestBodySize = $event->getRequest()->headers->get('Content-Length');
        if (null !== $requestBodySize) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, (int) $requestBodySize);
        }

        $responseBodySize = $response->headers->get('Content-Length');
        if (null !== $responseBodySize) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, (int) $responseBodySize);
        }

        if ($statusCode >= $this->errorStatusThreshold && !$hadException) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        if ($event->isMainRequest()) {
            $responsePropagator = Globals::responsePropagator();
            $responsePropagator->inject($response, ResponsePropagationSetter::instance(), Context::getCurrent());
        }
    }

    public function onFinishRequestDetachScope(FinishRequestEvent $event): void
    {
        $scope = $this->getScope($event->getRequest());
        $scope?->detach();
    }

    /**
     * End sub-request spans immediately. Main request spans are ended on TERMINATE.
     *
     * The exception check is defensive: normally onResponse clears the attribute,
     * but if the response event itself fails, the exception flag may still be set.
     */
    public function onFinishRequestEndSpan(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();
        $span = $this->getSpan($request);
        if (null === $span) {
            return;
        }

        $data = $this->requestData[$request] ?? [];
        $exception = $data['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        } elseif ($event->isMainRequest()) {
            return;
        }

        $span->end();
        unset($this->requestData[$request]);
    }

    /**
     * End the main request span after the response has been sent to the client
     * and clean up references to allow garbage collection.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $span = $this->getSpan($request);
        $span?->end();

        unset($this->requestData[$request]);
    }

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;
        $this->requestData = new \WeakMap();
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
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

    private function getSpan(Request $request): ?SpanInterface
    {
        return ($this->requestData[$request] ?? [])['span'] ?? null;
    }

    private function getScope(Request $request): ?ScopeInterface
    {
        return ($this->requestData[$request] ?? [])['scope'] ?? null;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function requestAttributes(Request $request): array
    {
        $protocolVersion = $request->getProtocolVersion();
        if (null !== $protocolVersion) {
            $protocolVersion = str_replace('HTTP/', '', $protocolVersion);
        }

        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            UrlAttributes::URL_FULL => $request->getUri(),
            UrlAttributes::URL_PATH => $request->getPathInfo(),
            UrlAttributes::URL_SCHEME => $request->getScheme(),
            ServerAttributes::SERVER_ADDRESS => $request->getHost(),
            ServerAttributes::SERVER_PORT => $request->getPort(),
            UserAgentAttributes::USER_AGENT_ORIGINAL => $request->headers->get('User-Agent'),
            NetworkAttributes::NETWORK_PROTOCOL_VERSION => $protocolVersion,
        ];

        $queryString = $request->getQueryString();
        if (null !== $queryString) {
            $attributes[UrlAttributes::URL_QUERY] = $queryString;
        }

        if ($this->recordClientIp) {
            $clientIp = $request->getClientIp();
            if (null !== $clientIp) {
                $attributes[ClientAttributes::CLIENT_ADDRESS] = $clientIp;
            }
        }

        return $attributes;
    }
}
