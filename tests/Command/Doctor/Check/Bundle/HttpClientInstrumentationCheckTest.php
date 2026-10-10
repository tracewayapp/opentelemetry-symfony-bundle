<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Bundle;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\HttpClientInstrumentationCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class HttpClientInstrumentationCheckTest extends TestCase
{
    public function testSkippedWhenNoPassRecordedAnything(): void
    {
        $result = (new HttpClientInstrumentationCheck())->run(CheckTestHelper::context());

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testListsEveryFamilyAndTheExclusions(): void
    {
        $result = (new HttpClientInstrumentationCheck())->run(CheckTestHelper::context(params: [
            'open_telemetry.http_client.instrumented.symfony' => ['http_client', 'api.client'],
            'open_telemetry.http_client.instrumented.psr18' => [],
            'open_telemetry.http_client.instrumented.guzzle' => ['app.guzzle'],
            'open_telemetry.http_client.excluded_services' => ['app.legacy'],
        ]));

        self::assertSame(Status::Info, $result->status);
        self::assertSame('Symfony HttpClient: http_client, api.client; PSR-18: none; Guzzle: app.guzzle; excluded: app.legacy', $result->message);
        self::assertSame(['app.guzzle'], $result->details['guzzle']);
    }
}
