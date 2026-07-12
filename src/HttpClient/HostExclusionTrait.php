<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

/**
 * Shared host-exclusion logic for the HTTP client decorators: user-configured
 * hostnames plus automatic exclusion of the OTLP collector endpoint.
 */
trait HostExclusionTrait
{
    private ?string $otlpEndpoint = null;
    private bool $otlpEndpointResolved = false;

    /** @var list<string> */
    private readonly array $excludedHosts;

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

    private function resetHostExclusion(): void
    {
        $this->otlpEndpoint = null;
        $this->otlpEndpointResolved = false;
    }
}
