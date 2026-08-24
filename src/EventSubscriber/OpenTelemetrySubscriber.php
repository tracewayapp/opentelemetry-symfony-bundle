<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Instrumentation\TracerAwareTrait;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateResolver;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

/**
 * Automatic HTTP request instrumentation for Symfony using OpenTelemetry.
 *
 * Creates a SERVER span per request with proper URL path templates,
 * semantic conventions, sub-request handling, and exception recording.
 */
final class OpenTelemetrySubscriber implements EventSubscriberInterface, ResetInterface
{
    use TracerAwareTrait;

    /** @var string[] */
    private readonly array $excludedPaths;

    private readonly RouteTemplateResolver $routeTemplateResolver;

    /** @var \WeakMap<Request, array{span?: SpanInterface, scope?: ScopeInterface, exception?: \Throwable}> */
    private \WeakMap $requestData;

    /**
     * @param string   $tracerName               Instrumentation library name
     * @param string[] $excludedPaths            URL path prefixes to skip (must start with /)
     * @param bool     $recordClientIp           Whether to record client.address
     * @param int      $errorStatusThreshold     HTTP status codes >= this are marked as errors
     * @param int      $recordExceptionMinStatus Skip recordException() for HTTP exceptions below this status (0 = record all)
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedPaths = [],
        private readonly bool $recordClientIp = true,
        private readonly int $errorStatusThreshold = 500,
        ?RouteTemplateResolver $routeTemplateResolver = null,
        private readonly int $recordExceptionMinStatus = 0,
    ) {
        $this->excludedPaths = array_values($excludedPaths);
        $this->requestData = new \WeakMap();
        $this->routeTemplateResolver = $routeTemplateResolver ?? new RouteTemplateResolver();
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
            ->spanBuilder(HttpMethodResolver::spanNameMethod($request->getMethod()))
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
     * Once the router has resolved, set http.route and rename the span to
     * "{method} {route template}" (e.g. GET /api/items/{id}). Unrouted
     * requests keep the bare method name and no http.route, per semconv.
     */
    public function onRoute(RequestEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $request = $event->getRequest();
        $route = $this->routeTemplateResolver->resolve($request);
        if (null === $route) {
            return;
        }

        $span->updateName(\sprintf('%s %s', HttpMethodResolver::spanNameMethod($request->getMethod()), $route));
        $span->setAttribute(HttpAttributes::HTTP_ROUTE, $route);
    }

    public function onException(ExceptionEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($this->shouldRecordException($throwable)) {
            $span->recordException($throwable);
        }

        // Error status is irreversible, so defer the decision until the final status is known:
        // semconv says 4xx on SERVER spans MUST stay unset, and listeners may map any exception to 4xx.
        $data = $this->requestData[$event->getRequest()] ?? [];
        $data['exception'] = $throwable;
        $this->requestData[$event->getRequest()] = $data;
    }

    public function onResponse(ResponseEvent $event): void
    {
        $span = $this->getSpan($event->getRequest());
        if (null === $span || !$span->isRecording()) {
            return;
        }

        $data = $this->requestData[$event->getRequest()] ?? [];
        $exception = $data['exception'] ?? null;
        unset($data['exception']);
        $this->requestData[$event->getRequest()] = $data;

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();
        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        $requestBodySize = $event->getRequest()->headers->get('Content-Length');
        if (null !== $requestBodySize && ctype_digit($requestBodySize)) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, (int) $requestBodySize);
        }

        $responseBodySize = $response->headers->get('Content-Length');
        if (null !== $responseBodySize && ctype_digit($responseBodySize)) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, (int) $responseBodySize);
        }

        if ($statusCode >= $this->errorStatusThreshold) {
            if ($exception instanceof \Throwable) {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($exception));
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            } else {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
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
            // No response was produced, so the request genuinely ended with this error.
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($exception));
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
        $this->resetTracer();

        // Drain in-flight entries so aborted requests can't corrupt the context stack.
        foreach ($this->requestData as $data) {
            if (isset($data['scope'])) {
                $data['scope']->detach();
            }
            if (isset($data['span'])) {
                $data['span']->end();
            }
        }
        $this->requestData = new \WeakMap();
    }

    // Only filters the exception event; span status and error.type are decided later from the response.
    private function shouldRecordException(\Throwable $throwable): bool
    {
        return 0 === $this->recordExceptionMinStatus
            || !$throwable instanceof HttpExceptionInterface
            || $throwable->getStatusCode() >= $this->recordExceptionMinStatus;
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
        $method = $request->getMethod();
        $normalizedMethod = HttpMethodResolver::normalize($method);

        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $normalizedMethod,
            UrlAttributes::URL_PATH => $request->getPathInfo(),
            UrlAttributes::URL_SCHEME => $request->getScheme(),
            ServerAttributes::SERVER_ADDRESS => $request->getHost(),
            ServerAttributes::SERVER_PORT => $request->getPort(),
            UserAgentAttributes::USER_AGENT_ORIGINAL => $request->headers->get('User-Agent'),
            NetworkAttributes::NETWORK_PROTOCOL_VERSION => ProtocolVersion::fromServerProtocol($request->getProtocolVersion()),
        ];

        if ($normalizedMethod !== $method) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $method;
        }

        // The direct TCP peer, distinct from the proxy-resolved client.address.
        $peerAddress = $request->server->get('REMOTE_ADDR');
        if (\is_string($peerAddress) && '' !== $peerAddress) {
            $attributes[NetworkAttributes::NETWORK_PEER_ADDRESS] = $peerAddress;

            $peerPort = $request->server->get('REMOTE_PORT');
            if (is_numeric($peerPort)) {
                $attributes[NetworkAttributes::NETWORK_PEER_PORT] = (int) $peerPort;
            }
        }

        $queryString = $request->getQueryString();
        if (null !== $queryString) {
            $attributes[UrlAttributes::URL_QUERY] = UrlSanitizer::sanitizeQuery($queryString);
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
