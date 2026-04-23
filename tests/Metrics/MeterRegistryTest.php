<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Metrics;

use OpenTelemetry\SDK\Metrics\Data\Sum;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistry;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class MeterRegistryTest extends TestCase
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

    public function testCounterReturnsSameInstanceForSameName(): void
    {
        $registry = new MeterRegistry('test');

        self::assertSame($registry->counter('foo'), $registry->counter('foo'));
    }

    public function testHistogramReturnsSameInstanceForSameName(): void
    {
        $registry = new MeterRegistry('test');

        self::assertSame($registry->histogram('foo'), $registry->histogram('foo'));
    }

    public function testUpDownCounterReturnsSameInstanceForSameName(): void
    {
        $registry = new MeterRegistry('test');

        self::assertSame($registry->upDownCounter('foo'), $registry->upDownCounter('foo'));
    }

    public function testCounterEmitsValuesAndAttributes(): void
    {
        $registry = new MeterRegistry('test');
        $registry->counter('my.counter', description: 'desc')->add(3, ['tag' => 'a']);
        $registry->counter('my.counter')->add(1, ['tag' => 'b']);

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('my.counter', $metrics);
        $metric = $metrics['my.counter'];
        self::assertSame('desc', $metric->description);
        self::assertInstanceOf(Sum::class, $metric->data);

        $byTag = [];
        foreach ($metric->data->dataPoints as $dp) {
            $byTag[(string) $dp->attributes->toArray()['tag']] = $dp->value;
        }
        self::assertSame(3, $byTag['a']);
        self::assertSame(1, $byTag['b']);
    }

    public function testResetClearsInstrumentCache(): void
    {
        $registry = new MeterRegistry('test');
        $first = $registry->counter('foo');

        $registry->reset();

        self::assertNotSame($first, $registry->counter('foo'));
    }
}
