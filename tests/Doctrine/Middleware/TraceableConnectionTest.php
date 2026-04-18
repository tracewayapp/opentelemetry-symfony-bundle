<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Tests for DBAL 4 connection wrapper.
 *
 * Skipped when doctrine/dbal ^3.x is installed (incompatible signatures).
 *
 * @group dbal4
 */
final class TraceableConnectionTest extends TestCase
{
    use OTelTestTrait;

    private Connection $inner;

    /** @var \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4 */
    private $connection;

    protected function setUp(): void
    {
        if (interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)) {
            self::markTestSkipped('DBAL 4 is not installed.');
        }

        $this->setUpOTel();
        $this->inner = $this->createStub(Connection::class);
        $this->connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4(
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
        self::assertSame('mysql', $attributes['db.system']);
        self::assertSame('INSERT', $attributes['db.operation.name']);
        self::assertSame('INSERT', $attributes['db.operation']);
        self::assertSame('users', $attributes['db.collection.name']);
        self::assertSame('app_db', $attributes['db.namespace']);
        self::assertSame('app_db', $attributes['db.name']);
        self::assertSame('localhost', $attributes['server.address']);
        self::assertSame(3306, $attributes['server.port']);
        self::assertArrayNotHasKey('db.query.text', $attributes);
        self::assertArrayNotHasKey('db.statement', $attributes);
    }

    public function testExecWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $this->inner->method('exec')->willReturn(1);

        $connection->exec('INSERT INTO users (name) VALUES ("test")');

        $span = $this->exporter->getSpans()[0];
        self::assertSame('INSERT users', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('INSERT INTO users (name) VALUES ("test")', $attr['db.query.text']);
        self::assertSame('INSERT INTO users (name) VALUES ("test")', $attr['db.statement']);
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
        self::assertArrayNotHasKey('db.query.text', $spans[0]->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $spans[0]->getAttributes()->toArray());
    }

    public function testQueryWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $innerResult = $this->createStub(Result::class);
        $this->inner->method('query')->willReturn($innerResult);

        $connection->query('SELECT * FROM users WHERE id = 1');

        $span = $this->exporter->getSpans()[0];
        self::assertSame('SELECT users', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('SELECT * FROM users WHERE id = 1', $attr['db.query.text']);
        self::assertSame('SELECT * FROM users WHERE id = 1', $attr['db.statement']);
    }

    public function testPrepareReturnsTraceableStatement(): void
    {
        $innerStatement = $this->createStub(Statement::class);
        $this->inner->method('prepare')->willReturn($innerStatement);

        $result = $this->connection->prepare('SELECT * FROM users WHERE id = ?');

        self::assertInstanceOf(\Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableStatementDbal4::class, $result);
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

        $events = $spans[0]->getEvents();
        self::assertNotEmpty($events);
        self::assertSame('exception', $events[0]->getName());
    }

    public function testExceptionSpanNameWithRecordStatementsDisabled(): void
    {
        $this->inner->method('query')->willThrowException(new \RuntimeException('Timeout'));

        try {
            $this->connection->query('SELECT * FROM slow_table');
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame('SELECT slow_table', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }

    public function testExceptionSpanNameWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $this->inner->method('query')->willThrowException(new \RuntimeException('Timeout'));

        try {
            $connection->query('SELECT * FROM slow_table');
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame('SELECT slow_table', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('SELECT * FROM slow_table', $attr['db.query.text']);
        self::assertSame('SELECT * FROM slow_table', $attr['db.statement']);
    }

    public function testBeginTransactionWithRecordStatementsDisabled(): void
    {
        $this->connection->beginTransaction();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('BEGIN app_db', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }

    public function testBeginTransactionWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $connection->beginTransaction();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('BEGIN app_db', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('BEGIN', $attr['db.query.text']);
        self::assertSame('BEGIN', $attr['db.statement']);
    }

    public function testCommitWithRecordStatementsDisabled(): void
    {
        $this->connection->commit();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('COMMIT app_db', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }

    public function testCommitWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $connection->commit();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('COMMIT app_db', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('COMMIT', $attr['db.query.text']);
        self::assertSame('COMMIT', $attr['db.statement']);
    }

    public function testRollBackWithRecordStatementsDisabled(): void
    {
        $this->connection->rollBack();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('ROLLBACK app_db', $span->getName());
        self::assertArrayNotHasKey('db.query.text', $span->getAttributes()->toArray());
        self::assertArrayNotHasKey('db.statement', $span->getAttributes()->toArray());
    }

    public function testRollBackWithRecordStatementsEnabled(): void
    {
        $connection = $this->connectionWithStatements(true);
        $connection->rollBack();

        $span = $this->exporter->getSpans()[0];
        self::assertSame('ROLLBACK app_db', $span->getName());
        $attr = $span->getAttributes()->toArray();
        self::assertSame('ROLLBACK', $attr['db.query.text']);
        self::assertSame('ROLLBACK', $attr['db.statement']);
    }

    public function testSpanNameIsLowCardinalityForLongSql(): void
    {
        $connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4(
            $this->inner,
            'test-tracer',
            true,
            'mysql',
            'app_db',
            'localhost',
            3306,
        );

        $this->inner->method('exec')->willReturn(0);
        $longSql = 'SELECT id, name, email, phone, address, city, state, zip, country FROM very_long_table_name WHERE active = 1 AND verified = 1 AND role = "admin"';
        $connection->exec($longSql);

        $spans = $this->exporter->getSpans();
        self::assertSame('SELECT very_long_table_name', $spans[0]->getName());
        self::assertSame($longSql, $spans[0]->getAttributes()->toArray()['db.query.text']);
    }

    public function testSpanNameWithoutDbName(): void
    {
        $connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4(
            $this->inner,
            'test-tracer',
            false,
            'sqlite',
            null,
            null,
            null,
        );

        $this->inner->method('exec')->willReturn(0);
        $connection->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');

        $spans = $this->exporter->getSpans();
        self::assertSame('CREATE', $spans[0]->getName());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('sqlite', $attributes['db.system.name']);
        self::assertSame('sqlite', $attributes['db.system']);
        self::assertArrayNotHasKey('db.namespace', $attributes);
        self::assertArrayNotHasKey('db.name', $attributes);
        self::assertArrayNotHasKey('server.address', $attributes);
        self::assertArrayNotHasKey('server.port', $attributes);
    }

    public function testBeginTransactionRecordsExceptionOnFailure(): void
    {
        $inner = $this->createStub(Connection::class);
        $inner->method('beginTransaction')->willThrowException(new \RuntimeException('Lock wait timeout'));

        $connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4($inner, 'test-tracer', false, 'mysql', 'app_db', 'localhost', 3306);

        try {
            $connection->beginTransaction();
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Lock wait timeout', $spans[0]->getStatus()->getDescription());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testCommitRecordsExceptionOnFailure(): void
    {
        $inner = $this->createStub(Connection::class);
        $inner->method('commit')->willThrowException(new \RuntimeException('Commit failed'));

        $connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4($inner, 'test-tracer', false, 'mysql', 'app_db', 'localhost', 3306);

        try {
            $connection->commit();
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Commit failed', $spans[0]->getStatus()->getDescription());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testRollBackRecordsExceptionOnFailure(): void
    {
        $inner = $this->createStub(Connection::class);
        $inner->method('rollBack')->willThrowException(new \RuntimeException('Rollback failed'));

        $connection = new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4($inner, 'test-tracer', false, 'mysql', 'app_db', 'localhost', 3306);

        try {
            $connection->rollBack();
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Rollback failed', $spans[0]->getStatus()->getDescription());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testQueryRecordsExceptionOnFailure(): void
    {
        $this->inner->method('query')->willThrowException(new \RuntimeException('Syntax error'));

        try {
            $this->connection->query('SELEC * FROM users');
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('Syntax error', $spans[0]->getStatus()->getDescription());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    private function connectionWithStatements(bool $recordStatements): \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4
    {
        return new \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4(
            $this->inner,
            'test-tracer',
            $recordStatements,
            'mysql',
            'app_db',
            'localhost',
            3306,
        );
    }
}
