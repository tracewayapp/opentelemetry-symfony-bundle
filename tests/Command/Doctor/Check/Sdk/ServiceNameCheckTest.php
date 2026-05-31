<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\ServiceNameCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class ServiceNameCheckTest extends TestCase
{
    public function testErrorWhenUnset(): void
    {
        $result = (new ServiceNameCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('unknown_service', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testOkWhenSet(): void
    {
        $result = (new ServiceNameCheck())->run(CheckTestHelper::context([
            'OTEL_SERVICE_NAME' => 'checkout-api',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertStringContainsString('checkout-api', $result->message);
        self::assertSame('checkout-api', $result->details['value']);
    }

    public function testOkWhenSetViaResourceAttributes(): void
    {
        $result = (new ServiceNameCheck())->run(CheckTestHelper::context([
            'OTEL_RESOURCE_ATTRIBUTES' => 'service.version=1.0,service.name=billing,deployment.environment=prod',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('OTEL_RESOURCE_ATTRIBUTES', $result->details['source']);
    }
}
