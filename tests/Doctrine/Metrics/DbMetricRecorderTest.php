<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Metrics;

use OpenTelemetry\API\Metrics\HistogramInterface;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class DbMetricRecorderTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testRecordEmitsDurationWithBaseAttributes(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', 'app_db', 'localhost', 5432);

        $recorder->record('SELECT id FROM users WHERE id = 1', hrtime(true), null);

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('db.client.operation.duration', $metrics);

        $duration = $metrics['db.client.operation.duration'];
        self::assertSame('s', $duration->unit);

        $points = [...$duration->data->dataPoints];
        self::assertCount(1, $points);

        $attr = $points[0]->attributes->toArray();
        self::assertSame('postgresql', $attr['db.system.name']);
        self::assertSame('app_db', $attr['db.namespace']);
        self::assertSame('localhost', $attr['server.address']);
        self::assertSame(5432, $attr['server.port']);
        self::assertSame('SELECT', $attr['db.operation.name']);
        self::assertSame('users', $attr['db.collection.name']);
        self::assertArrayNotHasKey('error.type', $attr);
    }

    public function testRecordOmitsOptionalAttributesWhenAbsent(): void
    {
        $recorder = new DbMetricRecorder('test', 'sqlite', null, null, null);

        $recorder->record('BEGIN', hrtime(true), null);

        $metrics = $this->collectMetrics();
        $points = [...$metrics['db.client.operation.duration']->data->dataPoints];
        $attr = $points[0]->attributes->toArray();

        self::assertSame('sqlite', $attr['db.system.name']);
        self::assertArrayNotHasKey('db.namespace', $attr);
        self::assertArrayNotHasKey('server.address', $attr);
        self::assertArrayNotHasKey('server.port', $attr);
        self::assertSame('BEGIN', $attr['db.operation.name']);
        self::assertArrayNotHasKey('db.collection.name', $attr);
    }

    public function testRecordOmitsCollectionWhenSqlIsAmbiguous(): void
    {
        $recorder = new DbMetricRecorder('test', 'mysql', 'app_db', null, null);

        $recorder->record('CREATE TABLE foo (id INT)', hrtime(true), null);

        $metrics = $this->collectMetrics();
        $attr = [...$metrics['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame('CREATE', $attr['db.operation.name']);
        self::assertArrayNotHasKey('db.collection.name', $attr);
    }

    public function testFailureAddsErrorType(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', 'app_db', null, null);

        $recorder->record('SELECT 1', hrtime(true), new \PDOException('connection lost'));

        $metrics = $this->collectMetrics();
        $attr = [...$metrics['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame(\PDOException::class, $attr['error.type']);
    }

    public function testAnonymousExceptionFallsBackToParentClass(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', null, null, null);

        $recorder->record('SELECT 1', hrtime(true), new class('boom') extends \RuntimeException {});

        $metrics = $this->collectMetrics();
        $attr = [...$metrics['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame('RuntimeException', $attr['error.type']);
    }

    public function testHistogramUsesSecondBasedBuckets(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', null, null, null);

        $recorder->record('SELECT 1', hrtime(true), null);

        $metrics = $this->collectMetrics();
        $points = [...$metrics['db.client.operation.duration']->data->dataPoints];

        self::assertSame(
            DbMetricRecorder::DURATION_BUCKET_BOUNDARIES,
            $points[0]->explicitBounds,
        );
    }

    public function testRecordSwallowsHistogramFailure(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', null, null, null);

        $broken = $this->createMock(HistogramInterface::class);
        $broken->method('record')->willThrowException(new \RuntimeException('metrics down'));

        $reflection = new \ReflectionClass($recorder);
        $duration = $reflection->getProperty('duration');
        $duration->setAccessible(true);
        $duration->setValue($recorder, $broken);

        $recorder->record('SELECT 1', hrtime(true), null);

        self::assertTrue(true);
    }

    public function testResetClearsCachedMeter(): void
    {
        $recorder = new DbMetricRecorder('test', 'postgresql', null, null, null);

        $recorder->record('SELECT 1', hrtime(true), null);
        $recorder->reset();
        $recorder->record('SELECT 1', hrtime(true), null);

        $metrics = $this->collectMetrics();
        $points = [...$metrics['db.client.operation.duration']->data->dataPoints];
        self::assertSame(2, $points[0]->count);
    }
}
