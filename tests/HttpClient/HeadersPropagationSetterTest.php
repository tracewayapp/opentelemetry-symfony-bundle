<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\HttpClient\HeadersPropagationSetter;

final class HeadersPropagationSetterTest extends TestCase
{
    public function testImplementsPropagationSetterInterface(): void
    {
        self::assertInstanceOf(PropagationSetterInterface::class, HeadersPropagationSetter::instance());
    }

    public function testInstanceReturnsSameObject(): void
    {
        self::assertSame(HeadersPropagationSetter::instance(), HeadersPropagationSetter::instance());
    }

    public function testSetInjectsHeader(): void
    {
        $carrier = [];
        $setter = HeadersPropagationSetter::instance();

        $setter->set($carrier, 'traceparent', '00-abc-def-01');

        self::assertSame('00-abc-def-01', $carrier['traceparent']);
    }

    public function testSetOverwritesExistingKey(): void
    {
        $carrier = ['traceparent' => 'old'];
        $setter = HeadersPropagationSetter::instance();

        $setter->set($carrier, 'traceparent', 'new');

        self::assertSame('new', $carrier['traceparent']);
    }
}
