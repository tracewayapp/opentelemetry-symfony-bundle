<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Tests for DBAL 3 connection wrapper.
 *
 * These tests can only run when doctrine/dbal ^3.6 is installed.
 * With DBAL 4, the Dbal3 classes cannot be loaded (incompatible return types).
 */
#[Group('dbal3')]
final class TraceableConnectionDbal3Test extends TestCase
{
    use OTelTestTrait;

    private Connection $inner;

    /** @var \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal3 */
    private $connection;

    protected function setUp(): void
    {
        if (!interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 3 is not installed.');
        }

        $this->setUpOTel();
        $this->inner = $this->createStub(Connection::class);

        /* @phpstan-ignore-next-line */
        $this->connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal3(
            $this->inner,
            'test-tracer',
            false,
            'mysql',
            'app_db',
            'localhost',
            3306,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->exporter)) {
            $this->tearDownOTel();
        }
    }

    public function testExecCreatesClientSpan(): void
    {
        $this->inner->method('exec')->willReturn(1);

        $result = $this->connection->exec('INSERT INTO users (name) VALUES ("test")');

        self::assertSame(1, $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('INSERT users', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('mysql', $attributes['db.system.name']);
        self::assertSame('INSERT', $attributes['db.operation.name']);
    }

    public function testQueryCreatesClientSpan(): void
    {
        $innerResult = $this->createStub(Result::class);
        $this->inner->method('query')->willReturn($innerResult);

        $result = $this->connection->query('SELECT * FROM users WHERE id = 1');

        self::assertSame($innerResult, $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('SELECT users', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
    }

    public function testBeginTransactionReturnsBool(): void
    {
        $this->connection->beginTransaction();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('BEGIN app_db', $span->getName());
    }

    public function testCommitReturnsBool(): void
    {
        $this->connection->commit();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('COMMIT app_db', $span->getName());
    }

    public function testRollBackReturnsBool(): void
    {
        $this->connection->rollBack();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('ROLLBACK app_db', $span->getName());
    }

    public function testExecRecordsExceptionOnFailure(): void
    {
        $this->inner->method('exec')->willThrowException(new \RuntimeException('Connection lost'));

        try {
            $this->connection->exec('DELETE FROM users');
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Connection lost', $spans[0]->getStatus()->getDescription());
        self::assertSame(\RuntimeException::class, $spans[0]->getAttributes()->toArray()['error.type']);
    }

    public function testDriverExceptionRecordsSqlState(): void
    {
        $driverException = new class('duplicate key') extends \Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string
            {
                return '23505';
            }
        };
        $this->inner->method('exec')->willThrowException($driverException);

        try {
            $this->connection->exec('INSERT INTO users VALUES (1)');
            self::fail('Expected exception');
        } catch (\Doctrine\DBAL\Driver\Exception) {
        }

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('23505', $attributes['db.response.status_code']);
        self::assertSame('Exception', $attributes['error.type']);
    }

    public function testPrepareReturnsTraceableStatement(): void
    {
        $innerStatement = $this->createStub(Statement::class);
        $this->inner->method('prepare')->willReturn($innerStatement);

        $result = $this->connection->prepare('SELECT * FROM users WHERE id = ?');

        self::assertInstanceOf(
            \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal3::class,
            $result,
        );
    }

    public function testOnlyWithParentSuppressesRootSpan(): void
    {
        $connection = $this->connectionWithParentGate();
        $this->inner->method('exec')->willReturn(1);

        $result = $connection->exec('SELECT id FROM messenger_messages');

        self::assertSame(1, $result);
        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testOnlyWithParentSuppressesTransactionSpans(): void
    {
        $connection = $this->connectionWithParentGate();

        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();

        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testOnlyWithParentCreatesChildSpanUnderActiveParent(): void
    {
        $connection = $this->connectionWithParentGate();
        $this->inner->method('exec')->willReturn(1);

        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $connection->exec('DELETE FROM messenger_messages WHERE id = 1');
        } finally {
            $scope->detach();
            $parent->end();
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('DELETE messenger_messages', $spans[0]->getName());
        self::assertSame($parent->getContext()->getSpanId(), $spans[0]->getParentSpanId());
    }

    public function testOnlyWithParentPropagatesToPreparedStatement(): void
    {
        $innerResult = $this->createStub(Result::class);
        $innerStatement = $this->createStub(Statement::class);
        $innerStatement->method('execute')->willReturn($innerResult);
        $this->inner->method('prepare')->willReturn($innerStatement);

        $statement = $this->connectionWithParentGate()->prepare('SELECT * FROM messenger_messages');
        $result = $statement->execute();

        self::assertSame($innerResult, $result);
        self::assertCount(0, $this->exporter->getSpans());
    }

    private function connectionWithParentGate(): \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal3
    {
        /* @phpstan-ignore-next-line */
        return new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal3(
            $this->inner,
            'test-tracer',
            false,
            'mysql',
            'app_db',
            'localhost',
            3306,
            true,
        );
    }
}
