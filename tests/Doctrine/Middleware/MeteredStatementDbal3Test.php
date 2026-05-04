<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * @group dbal3
 */
final class MeteredStatementDbal3Test extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        if (!interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 3 is not installed.');
        }

        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        if (isset($this->metricExporter)) {
            $this->tearDownOTel();
        }
    }

    public function testExecuteWithoutParamsEmitsDuration(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $recorder = new DbMetricRecorder('test', 'mysql', 'app', null, null);

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal3(
            $inner,
            $recorder,
            'UPDATE accounts SET active = 1 WHERE id = ?',
        );

        $statement->execute();

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('UPDATE', $attr['db.operation.name']);
        self::assertSame('accounts', $attr['db.collection.name']);
    }

    public function testExecuteWithParamsEmitsDuration(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $recorder = new DbMetricRecorder('test', 'mysql', 'app', null, null);

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal3(
            $inner,
            $recorder,
            'SELECT * FROM users WHERE id = ?',
        );

        $statement->execute([1]);

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('SELECT', $attr['db.operation.name']);
    }

    public function testExecuteFailureAddsErrorTypeAndRethrows(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willThrowException(new \LogicException('bad params'));

        $recorder = new DbMetricRecorder('test', 'mysql', null, null, null);

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredStatementDbal3(
            $inner,
            $recorder,
            'SELECT 1',
        );

        try {
            $statement->execute();
            self::fail('Expected LogicException');
        } catch (\LogicException) {
        }

        $attr = [...$this->collectMetrics()['db.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('LogicException', $attr['error.type']);
    }
}
