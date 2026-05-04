<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredConnectionDbal4;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal4;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * @group dbal4
 */
final class MeteredConnectionTest extends TestCase
{
    use OTelTestTrait;

    private Connection $inner;
    private MeteredConnectionDbal4 $connection;

    protected function setUp(): void
    {
        if (interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 4 is not installed.');
        }

        $this->setUpOTel();
        $this->inner = $this->createStub(Connection::class);

        $recorder = new DbMetricRecorder('test', 'postgresql', 'app_db', 'localhost', 5432);
        $this->connection = new MeteredConnectionDbal4($this->inner, $recorder);
    }

    protected function tearDown(): void
    {
        if (isset($this->metricExporter)) {
            $this->tearDownOTel();
        }
    }

    public function testQueryEmitsDuration(): void
    {
        $this->inner->method('query')->willReturn($this->createStub(Result::class));

        $this->connection->query('SELECT id FROM users');

        $metrics = $this->collectMetrics();
        $points = [...$metrics['db.client.operation.duration']->data->dataPoints];
        self::assertCount(1, $points);

        $attr = $points[0]->attributes->toArray();
        self::assertSame('SELECT', $attr['db.operation.name']);
        self::assertSame('users', $attr['db.collection.name']);
    }

    public function testExecEmitsDuration(): void
    {
        $this->inner->method('exec')->willReturn(1);

        $this->connection->exec('INSERT INTO users (name) VALUES ("john")');

        $metrics = $this->collectMetrics();
        $attr = [...$metrics['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame('INSERT', $attr['db.operation.name']);
        self::assertSame('users', $attr['db.collection.name']);
    }

    public function testQueryFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('query')->willThrowException(new \RuntimeException('table missing'));

        try {
            $this->connection->query('SELECT * FROM users');
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
    }

    public function testTransactionControlEmitsMetrics(): void
    {
        $this->connection->beginTransaction();
        $this->connection->commit();
        $this->connection->beginTransaction();
        $this->connection->rollBack();

        $metrics = $this->collectMetrics();
        $points = [...$metrics['db.client.operation.duration']->data->dataPoints];

        $countsByOperation = [];
        foreach ($points as $point) {
            $op = $point->attributes->toArray()['db.operation.name'];
            $countsByOperation[$op] = $point->count;
        }
        ksort($countsByOperation);

        self::assertSame(['BEGIN' => 2, 'COMMIT' => 1, 'ROLLBACK' => 1], $countsByOperation);
    }

    public function testPrepareWrapsStatement(): void
    {
        $this->inner->method('prepare')->willReturn($this->createStub(Statement::class));

        $statement = $this->connection->prepare('SELECT id FROM users WHERE id = ?');

        self::assertInstanceOf(MeteredStatementDbal4::class, $statement);
    }

    public function testRecorderFailureDoesNotMaskQueryException(): void
    {
        $this->inner->method('query')->willThrowException(new \DomainException('app error'));

        try {
            $this->connection->query('SELECT 1');
            self::fail('Expected DomainException');
        } catch (\DomainException $caught) {
            self::assertSame('app error', $caught->getMessage());
        }
    }

    public function testExecFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('exec')->willThrowException(new \RuntimeException('connection lost'));

        try {
            $this->connection->exec('DELETE FROM users');
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
        self::assertSame('DELETE', $attr['db.operation.name']);
    }

    public function testBeginTransactionFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('beginTransaction')->willThrowException(new \RuntimeException('cannot begin'));

        try {
            $this->connection->beginTransaction();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
        self::assertSame('BEGIN', $attr['db.operation.name']);
    }

    public function testCommitFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('commit')->willThrowException(new \RuntimeException('commit failed'));

        try {
            $this->connection->commit();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
        self::assertSame('COMMIT', $attr['db.operation.name']);
    }

    public function testRollBackFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('rollBack')->willThrowException(new \RuntimeException('rollback failed'));

        try {
            $this->connection->rollBack();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
        self::assertSame('ROLLBACK', $attr['db.operation.name']);
    }
}
