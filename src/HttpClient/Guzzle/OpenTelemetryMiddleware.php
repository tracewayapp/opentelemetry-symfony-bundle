<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Guzzle;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestTracer;

/**
 * Guzzle handler-stack middleware: one CLIENT span and one http.client.* metric
 * measurement per attempt, with http.request.resend_count from Guzzle's
 * redirect and retry middlewares.
 *
 * Push it onto the stack (not unshift) so it sits below those middlewares and
 * sees every redirected or retried request:
 *
 *     $stack = HandlerStack::create();
 *     $stack->push($middleware, 'otel');
 */
final class OpenTelemetryMiddleware implements ResetInterface
{
    public const NAME = 'otel';

    private readonly RequestTracer $tracer;

    /**
     * @param string[] $excludedHosts
     */
    public function __construct(
        string $tracerName = 'opentelemetry-symfony',
        array $excludedHosts = [],
        ?RequestTracer $tracer = null,
        private readonly ?RequestMeter $meter = null,
    ) {
        $this->tracer = $tracer ?? new RequestTracer($tracerName, $excludedHosts);
    }

    /**
     * @param callable(RequestInterface, array<mixed>): PromiseInterface $handler
     *
     * @return callable(RequestInterface, array<mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        $traced = function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $traced = $this->tracer->shouldTrace($request);
            $metered = null !== $this->meter && $this->meter->shouldMeter($request);
            if (!$traced && !$metered) {
                return $handler($request, $options);
            }

            $span = null;
            if ($traced) {
                [$span, $request] = $this->tracer->start($request, self::resendCount($options));
            }
            $measurement = $metered ? $this->meter->start($request) : null;

            try {
                $promise = $handler($request, $options);
            } catch (\Throwable $e) {
                $this->fail($span, $measurement, $e);

                throw $e;
            }

            return $promise->then(
                function (mixed $response) use ($span, $measurement): mixed {
                    if ($response instanceof ResponseInterface) {
                        null !== $span && $this->tracer->recordResponse($span, $response);
                        null !== $measurement && $this->meter?->recordResponse($measurement, $response);
                    }
                    $span?->end();

                    return $response;
                },
                function (mixed $reason) use ($span, $measurement): PromiseInterface {
                    $this->fail($span, $measurement, $reason instanceof \Throwable ? $reason : new \RuntimeException(\is_scalar($reason) ? (string) $reason : 'Request rejected'));

                    return Create::rejectionFor($reason);
                },
            );
        };

        return $traced;
    }

    public function reset(): void
    {
        $this->tracer->reset();
        $this->meter?->reset();
    }

    /**
     * @param array{start: int|float, attributes: array<non-empty-string, string|int>, requestBodySize: ?int}|null $measurement
     */
    private function fail(?SpanInterface $span, ?array $measurement, \Throwable $e): void
    {
        if (null !== $span) {
            $this->tracer->recordFailure($span, $e);
            $span->end();
        }
        if (null !== $measurement) {
            $this->meter?->recordFailure($measurement, $e);
        }
    }

    /**
     * Redirects and retries re-enter the stack with Guzzle's own counters in the options.
     *
     * @param array<mixed> $options
     */
    private static function resendCount(array $options): int
    {
        $redirects = $options['__redirect_count'] ?? 0;
        $retries = $options['retries'] ?? 0;

        return (\is_int($redirects) ? $redirects : 0) + (\is_int($retries) ? $retries : 0);
    }
}
