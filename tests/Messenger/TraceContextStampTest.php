<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Traceway\OpenTelemetryBundle\Messenger\TraceContextStamp;

final class TraceContextStampTest extends TestCase
{
    public function testImplementsStampInterface(): void
    {
        $stamp = new TraceContextStamp([]);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    public function testReturnsHeaders(): void
    {
        $headers = [
            'traceparent' => '00-abc123-def456-01',
            'tracestate' => 'vendor=value',
        ];

        $stamp = new TraceContextStamp($headers);

        self::assertSame($headers, $stamp->getHeaders());
    }

    public function testEmptyHeaders(): void
    {
        $stamp = new TraceContextStamp([]);
        self::assertSame([], $stamp->getHeaders());
    }
}
