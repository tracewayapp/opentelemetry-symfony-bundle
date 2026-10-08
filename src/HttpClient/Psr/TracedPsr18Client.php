<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Psr;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates any PSR-18 client with a CLIENT span per request and W3C context propagation.
 */
final class TracedPsr18Client implements ClientInterface, ResetInterface
{
    private readonly RequestTracer $tracer;

    /**
     * @param string[] $excludedHosts
     */
    public function __construct(
        private readonly ClientInterface $client,
        string $tracerName = 'opentelemetry-symfony',
        array $excludedHosts = [],
        ?RequestTracer $tracer = null,
    ) {
        $this->tracer = $tracer ?? new RequestTracer($tracerName, $excludedHosts);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->tracer->shouldTrace($request)) {
            return $this->client->sendRequest($request);
        }

        [$span, $request, $context] = $this->tracer->start($request);
        $scope = $context->activate();

        try {
            $response = $this->client->sendRequest($request);
            $this->tracer->recordResponse($span, $response);

            return $response;
        } catch (\Throwable $e) {
            $this->tracer->recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function getInnerClient(): ClientInterface
    {
        return $this->client;
    }

    public function reset(): void
    {
        $this->tracer->reset();

        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
