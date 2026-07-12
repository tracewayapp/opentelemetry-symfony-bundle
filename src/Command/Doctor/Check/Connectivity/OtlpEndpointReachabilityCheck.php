<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Connectivity;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\NetworkCheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class OtlpEndpointReachabilityCheck implements NetworkCheckInterface
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function name(): string
    {
        return 'otlp_endpoint_reachable';
    }

    public function label(): string
    {
        return 'OTLP endpoint is reachable';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Connectivity;
    }

    public function run(CheckContext $context): CheckResult
    {
        $exporter = $context->env->get('OTEL_TRACES_EXPORTER') ?? 'otlp';
        if ('otlp' !== $exporter) {
            return CheckResult::skipped(
                $this->name(),
                \sprintf('OTEL_TRACES_EXPORTER is %s, not otlp', $exporter),
            );
        }

        if (!class_exists(HttpClient::class)) {
            return CheckResult::skipped(
                $this->name(),
                'symfony/http-client not installed; cannot probe reachability',
            );
        }

        $endpoint = $context->env->get('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT')
            ?? $context->env->get('OTEL_EXPORTER_OTLP_ENDPOINT');

        if (null === $endpoint) {
            return CheckResult::skipped(
                $this->name(),
                'No OTLP endpoint configured (otlp_endpoint_configured will already have errored)',
            );
        }

        $protocol = $context->env->get('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL')
            ?? $context->env->get('OTEL_EXPORTER_OTLP_PROTOCOL')
            ?? 'http/protobuf';

        // gRPC servers reject HTTP/1.1, so a HEAD probe would report a healthy collector as down.
        if ('grpc' === $protocol && null === $this->httpClient) {
            return $this->probeTcp($endpoint, $context->networkTimeoutSeconds);
        }

        $probeUrl = $this->normalizeForProbe($endpoint, $protocol);

        $client = $this->httpClient ?? HttpClient::create([
            'timeout' => $context->networkTimeoutSeconds,
            'max_duration' => $context->networkTimeoutSeconds,
        ]);

        $started = microtime(true);
        try {
            $response = $client->request('HEAD', $probeUrl, [
                'timeout' => $context->networkTimeoutSeconds,
                'max_duration' => $context->networkTimeoutSeconds,
            ]);
            $statusCode = $response->getStatusCode();
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            return CheckResult::ok(
                $this->name(),
                \sprintf('OTLP endpoint reachable (HTTP %d, %dms)', $statusCode, $elapsed),
                [
                    'endpoint' => $endpoint,
                    'probe_url' => $probeUrl,
                    'status_code' => $statusCode,
                    'elapsed_ms' => $elapsed,
                ],
            );
        } catch (TransportExceptionInterface $e) {
            return CheckResult::error(
                $this->name(),
                \sprintf('OTLP endpoint unreachable: %s', $e->getMessage()),
                'Verify OTEL_EXPORTER_OTLP_ENDPOINT is correct, the collector is running, and any firewall/proxy allows the connection. Use --skip-network to silence this check (e.g. in CI without backend access).',
                [
                    'endpoint' => $endpoint,
                    'probe_url' => $probeUrl,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /** gRPC endpoints are bare host:port; synthesize http:// scheme for the HEAD probe. */
    private function normalizeForProbe(string $endpoint, string $protocol): string
    {
        if ('grpc' === $protocol && !str_contains($endpoint, '://')) {
            return 'http://'.$endpoint;
        }

        return $endpoint;
    }

    private function probeTcp(string $endpoint, float $timeoutSeconds): CheckResult
    {
        $parsed = parse_url(str_contains($endpoint, '://') ? $endpoint : 'tcp://'.$endpoint);
        $host = \is_array($parsed) ? ($parsed['host'] ?? null) : null;

        if (null === $host) {
            return CheckResult::error(
                $this->name(),
                \sprintf('Cannot parse gRPC endpoint "%s" for a TCP probe.', $endpoint),
                'Set OTEL_EXPORTER_OTLP_ENDPOINT to host:port (e.g. collector:4317) for the grpc protocol.',
                ['endpoint' => $endpoint],
            );
        }

        $port = \is_array($parsed) && isset($parsed['port']) ? (int) $parsed['port'] : 4317;

        $started = microtime(true);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $host, $port), $errno, $error, $timeoutSeconds);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (false === $socket) {
            return CheckResult::error(
                $this->name(),
                \sprintf('OTLP gRPC endpoint unreachable: %s (errno %d)', '' !== $error ? $error : 'connection failed', $errno),
                'Verify OTEL_EXPORTER_OTLP_ENDPOINT is correct, the collector is running, and any firewall/proxy allows the connection. Use --skip-network to silence this check (e.g. in CI without backend access).',
                ['endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'error' => $error],
            );
        }

        fclose($socket);

        return CheckResult::ok(
            $this->name(),
            \sprintf('OTLP gRPC endpoint reachable (TCP %s:%d, %dms)', $host, $port, $elapsed),
            ['endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'elapsed_ms' => $elapsed],
        );
    }
}
