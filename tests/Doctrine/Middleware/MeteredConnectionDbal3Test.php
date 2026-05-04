<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Tests for DBAL 3 connection wrapper.
 *
 * @group dbal3
 */
final class MeteredConnectionDbal3Test extends TestCase
{
    use OTelTestTrait;

    private Connection $inner;

    /** @var \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredConnectionDbal3 */
    private $connection;

    protected function setUp(): void
    {
        if (!interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 3 is not installed.');
        }

        $this->setUpOTel();
        $this->inner = $this->createStub(Connection::class);

        $recorder = new DbMetricRecorder('test', 'mysql', 'app_db', 'localhost', 3306);

        /** @phpstan-ignore-next-line */
        $this->connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredConnectionDbal3(
            $this->inner,
            $recorder,
        );
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

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('SELECT', $attr['db.operation.name']);
        self::assertSame('users', $attr['db.collection.name']);
    }

    public function testExecEmitsDuration(): void
    {
        $this->inner->method('exec')->willReturn(1);

        $this->connection->exec('DELETE FROM logs');

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('DELETE', $attr['db.operation.name']);
    }

    public function testTransactionsEmitMetrics(): void
    {
        $this->inner->method('beginTransaction')->willReturn(true);
        $this->inner->method('commit')->willReturn(true);
        $this->inner->method('rollBack')->willReturn(true);

        $this->connection->beginTransaction();
        $this->connection->commit();
        $this->connection->beginTransaction();
        $this->connection->rollBack();

        $points = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints];
        $countsByOperation = [];
        foreach ($points as $p) {
            $countsByOperation[$p->attributes->toArray()['db.operation.name']] = $p->count;
        }
        ksort($countsByOperation);

        self::assertSame(['BEGIN' => 2, 'COMMIT' => 1, 'ROLLBACK' => 1], $countsByOperation);
    }

    public function testQueryFailureAddsErrorTypeAndRethrows(): void
    {
        $this->inner->method('query')->willThrowException(new \RuntimeException('lost'));

        try {
            $this->connection->query('SELECT 1');
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('RuntimeException', $attr['error.type']);
    }

    public function testPrepareWrapsStatement(): void
    {
        $this->inner->method('prepare')->willReturn($this->createStub(Statement::class));

        $statement = $this->connection->prepare('SELECT id FROM users WHERE id = ?');

        self::assertInstanceOf(
            \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal3::class,
            $statement,
        );
    }
}
