<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\DbSpanBudget;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableDriver;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableMiddlewareTest extends TestCase
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

    public function testWrapReturnsTraceableDriver(): void
    {
        $middleware = new TraceableMiddleware('test-tracer', false);
        $inner = $this->createStub(DriverInterface::class);

        $result = $middleware->wrap($inner);

        self::assertInstanceOf(TraceableDriver::class, $result);
    }

    public function testMaxSpansPerTraceCapsStatementsUnderOneTrace(): void
    {
        $connection = $this->connect(new TraceableMiddleware('test-tracer', false, false, 3));

        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('process MlFlats')->startSpan();
        $scope = $parent->activate();
        try {
            for ($i = 0; $i < 10; ++$i) {
                $connection->exec('UPDATE money_log SET amount = 1 WHERE id = '.$i);
            }
        } finally {
            $scope->detach();
            $parent->end();
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(4, $spans);
        self::assertSame('process MlFlats', $spans[3]->getName());
        self::assertSame(7, $spans[3]->getAttributes()->get(DbSpanBudget::DROPPED_ATTRIBUTE));
        foreach (\array_slice($spans, 0, 3) as $span) {
            self::assertSame('UPDATE money_log', $span->getName());
        }
    }

    public function testPreparedStatementsShareTheConnectionBudget(): void
    {
        $connection = $this->connect(new TraceableMiddleware('test-tracer', false, false, 2));

        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $parent->activate();
        try {
            $connection->exec('SELECT 1');
            $connection->prepare('UPDATE money_log SET amount = ? WHERE id = ?')->execute();
            $connection->prepare('UPDATE money_log SET amount = ? WHERE id = ?')->execute();
        } finally {
            $scope->detach();
            $parent->end();
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(3, $spans);
        self::assertSame(1, $spans[2]->getAttributes()->get(DbSpanBudget::DROPPED_ATTRIBUTE));
    }

    private function connect(TraceableMiddleware $middleware): Connection
    {
        $statement = $this->createStub(DriverInterface\Statement::class);
        $statement->method('execute')->willReturn($this->createStub(DriverInterface\Result::class));
        $innerConnection = $this->createStub(Connection::class);
        $innerConnection->method('exec')->willReturn(1);
        $innerConnection->method('prepare')->willReturn($statement);
        $innerDriver = $this->createStub(DriverInterface::class);
        $innerDriver->method('connect')->willReturn($innerConnection);

        return $middleware->wrap($innerDriver)->connect(['driver' => 'pdo_mysql', 'dbname' => 'app']);
    }
}
