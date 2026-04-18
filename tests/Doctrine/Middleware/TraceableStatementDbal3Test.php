<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Tests for DBAL 3 statement wrapper.
 *
 * These tests can only run when doctrine/dbal ^3.6 is installed.
 * With DBAL 4, the Dbal3 classes cannot be loaded (incompatible return types).
 *
 * @group dbal3
 */
final class TraceableStatementDbal3Test extends TestCase
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
        if (isset($this->exporter)) {
            $this->tearDownOTel();
        }
    }

    public function testExecuteCreatesSpan(): void
    {
        $innerResult = $this->createStub(Result::class);
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($innerResult);

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal3(
            $inner,
            'test-tracer',
            true,
            'postgresql',
            'my_db',
            'db.example.com',
            5432,
            'SELECT * FROM orders WHERE user_id = ?',
        );

        $result = $statement->execute();

        self::assertSame($innerResult, $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('SELECT orders', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('postgresql', $attributes['db.system.name']);
        self::assertSame('SELECT', $attributes['db.operation.name']);
        self::assertSame('SELECT * FROM orders WHERE user_id = ?', $attributes['db.query.text']);
    }

    public function testExecuteWithParamsCreatesSpan(): void
    {
        $innerResult = $this->createStub(Result::class);
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($innerResult);

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal3(
            $inner,
            'test-tracer',
            true,
            'mysql',
            'app',
            null,
            null,
            'SELECT * FROM users WHERE id = ?',
        );

        $result = $statement->execute([1]);

        self::assertSame($innerResult, $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('SELECT users', $spans[0]->getName());
    }

    public function testExecuteRecordsException(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willThrowException(new \RuntimeException('Deadlock'));

        /** @phpstan-ignore-next-line */
        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal3(
            $inner,
            'test-tracer',
            false,
            'mysql',
            'app',
            null,
            null,
            'UPDATE accounts SET balance = balance - 100',
        );

        try {
            $statement->execute();
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Deadlock', $spans[0]->getStatus()->getDescription());
    }
}
