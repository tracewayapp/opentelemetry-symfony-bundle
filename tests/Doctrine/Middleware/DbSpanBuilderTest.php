<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\DbSpanBuilder;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class DbSpanBuilderTest extends TestCase
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

    public function testCreateSetsAllAttributes(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');

        $span = DbSpanBuilder::create(
            $tracer,
            'SELECT * FROM users WHERE id = ?',
            true,
            'postgresql',
            'my_db',
            'db.example.com',
            5432,
        )->startSpan();

        $span->end();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('SELECT users', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('postgresql', $attrs['db.system.name']);
        self::assertSame('postgresql', $attrs['db.system']);
        self::assertSame('SELECT', $attrs['db.operation.name']);
        self::assertSame('SELECT', $attrs['db.operation']);
        self::assertSame('SELECT users', $attrs['db.query.summary']);
        self::assertSame('users', $attrs['db.collection.name']);
        self::assertSame('my_db', $attrs['db.namespace']);
        self::assertSame('my_db', $attrs['db.name']);
        self::assertSame('SELECT * FROM users WHERE id = ?', $attrs['db.query.text']);
        self::assertSame('SELECT * FROM users WHERE id = ?', $attrs['db.statement']);
        self::assertSame('db.example.com', $attrs['server.address']);
        self::assertSame(5432, $attrs['server.port']);
    }

    public function testCreateWithoutRecordStatements(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');

        $span = DbSpanBuilder::create(
            $tracer,
            'DELETE FROM sessions',
            false,
            'mysql',
            'app_db',
            'localhost',
            3306,
        )->startSpan();

        $span->end();

        $spans = $this->exporter->getSpans();
        self::assertSame('DELETE sessions', $spans[0]->getName());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('sessions', $attrs['db.collection.name']);
        self::assertArrayNotHasKey('db.query.text', $attrs);
        self::assertArrayNotHasKey('db.statement', $attrs);
    }

    public function testCreateWithNullOptionals(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');

        $span = DbSpanBuilder::create(
            $tracer,
            'CREATE TABLE test (id INTEGER)',
            false,
            'sqlite',
            null,
            null,
            null,
        )->startSpan();

        $span->end();

        $spans = $this->exporter->getSpans();
        self::assertSame('CREATE', $spans[0]->getName());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('sqlite', $attrs['db.system.name']);
        self::assertArrayNotHasKey('db.collection.name', $attrs);
        self::assertArrayNotHasKey('db.namespace', $attrs);
        self::assertArrayNotHasKey('db.name', $attrs);
        self::assertArrayNotHasKey('server.address', $attrs);
        self::assertArrayNotHasKey('server.port', $attrs);
    }
}
