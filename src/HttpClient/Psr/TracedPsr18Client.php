<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient\Psr;

use OpenTelemetry\Context\Context;
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
        private readonly ?RequestMeter $meter = null,
    ) {
        $this->tracer = $tracer ?? new RequestTracer($tracerName, $excludedHosts);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $traced = $this->tracer->shouldTrace($request);
        $metered = null !== $this->meter && $this->meter->shouldMeter($request);
        if (!$traced && !$metered) {
            return $this->client->sendRequest($request);
        }

        $span = null;
        $context = Context::getCurrent();
        if ($traced) {
            [$span, $request, $context] = $this->tracer->start($request);
        }
        $measurement = null;
        if ($metered) {
            $measurement = $this->meter->start($request);
            $context = $this->meter->markInFlight($context);
        }
        $scope = $context->activate();

        try {
            $response = $this->client->sendRequest($request);
            null !== $span && $this->tracer->recordResponse($span, $response);
            null !== $measurement && $this->meter->recordResponse($measurement, $response);

            return $response;
        } catch (\Throwable $e) {
            null !== $span && $this->tracer->recordFailure($span, $e);
            null !== $measurement && $this->meter->recordFailure($measurement, $e);

            throw $e;
        } finally {
            $span?->end();
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
        $this->meter?->reset();

        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
