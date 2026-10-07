<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\DbSpanBudget;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class DbSpanBudgetTest extends TestCase
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

    public function testZeroDisablesTheCap(): void
    {
        $budget = new DbSpanBudget(0);

        self::assertFalse($budget->isEnabled());
        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $parent->activate();
        try {
            for ($i = 0; $i < 10; ++$i) {
                self::assertTrue($budget->tryAcquire());
            }
        } finally {
            $scope->detach();
            $parent->end();
        }
        self::assertSame(0, $budget->dropped());
    }

    public function testStatementsOverTheCapAreDroppedAndCountedOnTheParent(): void
    {
        $budget = new DbSpanBudget(2);
        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('process MlFlats')->startSpan();
        $scope = $parent->activate();

        try {
            self::assertTrue($budget->tryAcquire());
            self::assertTrue($budget->tryAcquire());
            self::assertFalse($budget->tryAcquire());
            self::assertFalse($budget->tryAcquire());
            self::assertFalse($budget->tryAcquire());
        } finally {
            $scope->detach();
            $parent->end();
        }

        self::assertSame(3, $budget->dropped());
        $attrs = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame(3, $attrs[DbSpanBudget::DROPPED_ATTRIBUTE]);
    }

    public function testBudgetResetsWhenTheTraceChanges(): void
    {
        $budget = new DbSpanBudget(1);
        $tracer = Globals::tracerProvider()->getTracer('test');

        $first = $tracer->spanBuilder('first')->startSpan();
        $scope = $first->activate();
        self::assertTrue($budget->tryAcquire());
        self::assertFalse($budget->tryAcquire());
        $scope->detach();
        $first->end();

        $second = $tracer->spanBuilder('second')->startSpan();
        $scope = $second->activate();
        self::assertTrue($budget->tryAcquire());
        self::assertFalse($budget->tryAcquire());
        $scope->detach();
        $second->end();

        self::assertSame(1, $budget->dropped());
        $spans = $this->exporter->getSpans();
        self::assertSame(1, $spans[0]->getAttributes()->get(DbSpanBudget::DROPPED_ATTRIBUTE));
        self::assertSame(1, $spans[1]->getAttributes()->get(DbSpanBudget::DROPPED_ATTRIBUTE));
    }

    public function testNoActiveParentIsNeverCapped(): void
    {
        $budget = new DbSpanBudget(1);

        self::assertTrue($budget->tryAcquire());
        self::assertTrue($budget->tryAcquire());
        self::assertSame(0, $budget->dropped());
    }
}
