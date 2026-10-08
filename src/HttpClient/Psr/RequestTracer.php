<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Psr;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Traceway\OpenTelemetryBundle\HttpClient\HeadersPropagationSetter;
use Traceway\OpenTelemetryBundle\HttpClient\HostExclusionTrait;
use Traceway\OpenTelemetryBundle\Instrumentation\TracerAwareTrait;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;
use Traceway\OpenTelemetryBundle\Util\UrlParts;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

/**
 * CLIENT span lifecycle for PSR-7 requests, shared by the PSR-18 decorator and the Guzzle middleware.
 */
final class RequestTracer
{
    use HostExclusionTrait;
    use TracerAwareTrait;

    /** @var ContextKeyInterface<bool>|null */
    private static ?ContextKeyInterface $inFlightKey = null;

    /**
     * @param string[] $excludedHosts
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedHosts = [],
    ) {
        $this->excludedHosts = array_map('strtolower', array_values($excludedHosts));
    }

    public function shouldTrace(RequestInterface $request): bool
    {
        if (!$this->isEnabled() || $this->isExcluded((string) $request->getUri())) {
            return false;
        }

        return true !== Context::getCurrent()->get(self::inFlightKey());
    }

    /**
     * Starts the span and returns it with the request carrying the injected
     * trace context and the context the request runs in.
     *
     * @return array{SpanInterface, RequestInterface, ContextInterface}
     */
    public function start(RequestInterface $request, int $resendCount = 0): array
    {
        $uri = $request->getUri();
        $method = $request->getMethod();
        $normalizedMethod = HttpMethodResolver::normalize($method);

        $builder = $this->getTracer()
            ->spanBuilder(HttpMethodResolver::spanNameMethod($method))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $normalizedMethod)
            ->setAttribute(UrlAttributes::URL_FULL, UrlSanitizer::sanitizeUrl((string) $uri));

        if ($normalizedMethod !== $method) {
            $builder->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $method);
        }

        $host = UrlParts::host(['host' => $uri->getHost()]);
        if (null !== $host) {
            $builder->setAttribute(ServerAttributes::SERVER_ADDRESS, $host);

            $parsed = ['scheme' => $uri->getScheme()];
            if (null !== $uri->getPort()) {
                $parsed['port'] = $uri->getPort();
            }
            $port = UrlParts::port($parsed);
            if (null !== $port) {
                $builder->setAttribute(ServerAttributes::SERVER_PORT, $port);
            }
        }

        if ('' !== $uri->getScheme()) {
            $builder->setAttribute(UrlAttributes::URL_SCHEME, $uri->getScheme());
        }

        if ($resendCount > 0) {
            $builder->setAttribute(HttpAttributes::HTTP_REQUEST_RESEND_COUNT, $resendCount);
        }

        $parent = Context::getCurrent();
        $span = $builder->setParent($parent)->startSpan();
        $context = $span->storeInContext($parent)->with(self::inFlightKey(), true);

        $headers = [];
        Globals::propagator()->inject($headers, HeadersPropagationSetter::instance(), $context);
        if (\is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $request = $request->withHeader($name, $value);
                }
            }
        }

        return [$span, $request, $context];
    }

    public function recordResponse(SpanInterface $span, ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        $version = ProtocolVersion::fromServerProtocol($response->getProtocolVersion());
        if (null !== $version) {
            $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $version);
        }

        $contentLength = $response->getHeaderLine('Content-Length');
        if (is_numeric($contentLength)) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, (int) $contentLength);
        }

        if ($statusCode >= 400) {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    public function recordFailure(SpanInterface $span, \Throwable $e): void
    {
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response instanceof ResponseInterface) {
                $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
            }
        }

        $span->recordException($e);
        $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }

    public function reset(): void
    {
        $this->resetTracer();
        $this->resetHostExclusion();
    }

    /** @return ContextKeyInterface<bool> */
    public static function inFlightKey(): ContextKeyInterface
    {
        return self::$inFlightKey ??= Context::createKey(self::class);
    }
}
