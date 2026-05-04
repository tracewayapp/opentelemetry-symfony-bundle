<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Metrics;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\SqlOperationExtractor;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Emits OpenTelemetry metrics for Doctrine DBAL operations.
 *
 * Bound to a single Doctrine connection at construction time so the per-connection
 * context (db.system.name, db.namespace, server.{address,port}) only resolves once.
 * Shared by {@see MeteredConnectionDbal3}, {@see MeteredConnectionDbal4} and their
 * statement counterparts; one recorder instance per connection.
 *
 * Metric: db.client.operation.duration (Histogram, seconds) — semconv Stable.
 */
final class DbMetricRecorder implements ResetInterface
{
    public const DURATION_BUCKET_BOUNDARIES = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;

    public function __construct(
        private readonly string $meterName,
        private readonly string $dbSystem,
        private readonly ?string $dbName,
        private readonly ?string $serverAddress,
        private readonly ?int $serverPort,
    ) {}

    public function record(string $sql, int|float $start, ?\Throwable $exception): void
    {
        try {
            $attributes = $this->baseAttributes();

            $operation = SqlOperationExtractor::extract($sql);
            if ('UNKNOWN' !== $operation) {
                $attributes['db.operation.name'] = $operation;
            }

            $target = SqlOperationExtractor::extractTarget($sql);
            if (null !== $target) {
                $attributes['db.collection.name'] = $target;
            }

            if (null !== $exception) {
                $attributes['error.type'] = ErrorTypeResolver::resolve($exception);
            }

            $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
            $this->getDurationHistogram()->record($durationSeconds, $attributes);
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    private function baseAttributes(): array
    {
        $attributes = [
            'db.system.name' => $this->dbSystem,
        ];

        if (null !== $this->dbName) {
            $attributes['db.namespace'] = $this->dbName;
        }
        if (null !== $this->serverAddress) {
            $attributes['server.address'] = $this->serverAddress;
        }
        if (null !== $this->serverPort) {
            $attributes['server.port'] = $this->serverPort;
        }

        return $attributes;
    }

    private function getDurationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->getMeter()->createHistogram(
            'db.client.operation.duration',
            's',
            'Duration of database client operations',
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKET_BOUNDARIES],
        );
    }

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter($this->meterName);
    }
}
