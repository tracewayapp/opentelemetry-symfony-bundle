<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Guzzle;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestTracer;

/**
 * Guzzle handler-stack middleware: one CLIENT span per attempt, with
 * http.request.resend_count from Guzzle's redirect and retry middlewares.
 *
 * Push it onto the stack (not unshift) so it sits below those middlewares and
 * sees every redirected or retried request:
 *
 *     $stack = HandlerStack::create();
 *     $stack->push($middleware, 'otel');
 */
final class TracingMiddleware implements ResetInterface
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
            if (!$this->tracer->shouldTrace($request)) {
                return $handler($request, $options);
            }

            [$span, $request] = $this->tracer->start($request, self::resendCount($options));

            try {
                $promise = $handler($request, $options);
            } catch (\Throwable $e) {
                $this->tracer->recordFailure($span, $e);
                $span->end();

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($span): ResponseInterface {
                    $this->tracer->recordResponse($span, $response);
                    $span->end();

                    return $response;
                },
                function (mixed $reason) use ($span): PromiseInterface {
                    $this->tracer->recordFailure($span, $reason instanceof \Throwable ? $reason : new \RuntimeException(\is_scalar($reason) ? (string) $reason : 'Request rejected'));
                    $span->end();

                    return Create::rejectionFor($reason);
                },
            );
        };

        return $traced;
    }

    public function reset(): void
    {
        $this->tracer->reset();
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
