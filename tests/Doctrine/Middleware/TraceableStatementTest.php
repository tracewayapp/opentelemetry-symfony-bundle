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
 * Tests for DBAL 4 statement wrapper.
 *
 * Skipped when doctrine/dbal ^3.x is installed (incompatible signatures).
 *
 * @group dbal4
 */
final class TraceableStatementTest extends TestCase
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
        if (isset($this->exporter)) {
            $this->tearDownOTel();
        }
    }

    public function testExecuteCreatesSpan(): void
    {
        $innerResult = $this->createStub(Result::class);
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($innerResult);

        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4(
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
        self::assertSame('postgresql', $attributes['db.system']);
        self::assertSame('SELECT', $attributes['db.operation.name']);
        self::assertSame('SELECT', $attributes['db.operation']);
        self::assertSame('orders', $attributes['db.collection.name']);
        self::assertSame('my_db', $attributes['db.namespace']);
        self::assertSame('my_db', $attributes['db.name']);
        self::assertSame('SELECT * FROM orders WHERE user_id = ?', $attributes['db.query.text']);
        self::assertSame('SELECT * FROM orders WHERE user_id = ?', $attributes['db.statement']);
        self::assertSame('db.example.com', $attributes['server.address']);
        self::assertSame(5432, $attributes['server.port']);
    }

    public function testExecuteRecordsException(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willThrowException(new \RuntimeException('Deadlock'));

        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4(
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

    public function testSpanNameIsLowCardinalityWhenRecordStatementsEnabled(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4(
            $inner,
            'test-tracer',
            true,
            'mysql',
            'app',
            null,
            null,
            'UPDATE accounts SET balance = ? WHERE id = ?',
        );

        $statement->execute();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('UPDATE accounts', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('UPDATE accounts SET balance = ? WHERE id = ?', $attr['db.query.text']);
        self::assertSame('UPDATE accounts SET balance = ? WHERE id = ?', $attr['db.statement']);
    }

    public function testSpanNameIsOperationWhenRecordStatementsDisabled(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4(
            $inner,
            'test-tracer',
            false,
            'mysql',
            'app',
            null,
            null,
            'SELECT password FROM users WHERE email = ?',
        );

        $statement->execute();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('SELECT users', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }

    public function testSpanNameIsOperationOnlyWhenDbNameNull(): void
    {
        $inner = $this->createStub(Statement::class);
        $inner->method('execute')->willReturn($this->createStub(Result::class));

        $statement = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4(
            $inner,
            'test-tracer',
            false,
            'sqlite',
            null,
            null,
            null,
            'INSERT INTO logs (message) VALUES (?)',
        );

        $statement->execute();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('INSERT logs', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }
}
