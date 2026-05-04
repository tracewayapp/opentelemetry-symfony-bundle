<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal4;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * @group dbal4
 */
final class MeteredStatementTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        if (interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 4 is not installed.');
        }

        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        if (isset($this->metricExporter)) {
            $this->tearDownOTel();
        }
    }

    public function testExecuteEmitsDurationWithSqlAttributes(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $recorder = new DbMetricRecorder('test', 'mysql', 'app_db', null, null);
        $statement = new MeteredStatementDbal4($inner, $recorder, 'UPDATE accounts SET active = 1 WHERE id = ?');

        $statement->execute();

        $metrics = $this->collectMetrics();
        $attr = [...$metrics['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame('UPDATE', $attr['db.operation.name']);
        self::assertSame('accounts', $attr['db.collection.name']);
    }

    public function testExecuteFailureAddsErrorTypeAndRethrows(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willThrowException(new \LogicException('bad params'));

        $recorder = new DbMetricRecorder('test', 'mysql', null, null, null);
        $statement = new MeteredStatementDbal4($inner, $recorder, 'SELECT 1');

        try {
            $statement->execute();
            self::fail('Expected LogicException');
        } catch (\LogicException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('LogicException', $attr['error.type']);
    }
}
