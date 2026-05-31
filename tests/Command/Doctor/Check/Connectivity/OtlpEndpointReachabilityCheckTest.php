<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Connectivity;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Connectivity\OtlpEndpointReachabilityCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\NetworkCheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class OtlpEndpointReachabilityCheckTest extends TestCase
{
    public function testImplementsNetworkCheckInterface(): void
    {
        self::assertInstanceOf(NetworkCheckInterface::class, new OtlpEndpointReachabilityCheck());
    }

    public function testSkippedWhenExporterIsNotOtlp(): void
    {
        $check = new OtlpEndpointReachabilityCheck(new MockHttpClient());
        $result = $check->run(CheckTestHelper::context([
            'OTEL_TRACES_EXPORTER' => 'console',
        ]));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testSkippedWhenEndpointNotConfigured(): void
    {
        $check = new OtlpEndpointReachabilityCheck(new MockHttpClient());
        $result = $check->run(CheckTestHelper::context([]));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testOkWhenHeadReturnsSuccess(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $check = new OtlpEndpointReachabilityCheck($http);

        $result = $check->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otlp.example.com:4318',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame(200, $result->details['status_code']);
        self::assertSame('https://otlp.example.com:4318', $result->details['endpoint']);
    }

    public function testOkEven405StatusBecauseHeadOftenRejected(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 405]));
        $check = new OtlpEndpointReachabilityCheck($http);

        $result = $check->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otlp.example.com:4318',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame(405, $result->details['status_code']);
    }

    public function testErrorOnTransportException(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Could not resolve host');
        });
        $check = new OtlpEndpointReachabilityCheck($http);

        $result = $check->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://nope.invalid:4318',
        ]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('Could not resolve host', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testGrpcEndpointGetsHttpSchemeForProbe(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('', ['http_code' => 200]);
        });

        $check = new OtlpEndpointReachabilityCheck($http);
        $check->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'collector.local:4317',
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
        ]));

        self::assertSame('http://collector.local:4317/', $capturedUrl);
    }

    public function testSignalSpecificEndpointOverridesGeneric(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('', ['http_code' => 200]);
        });

        $check = new OtlpEndpointReachabilityCheck($http);
        $check->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://generic.example.com:4318',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'https://traces.example.com:4318',
        ]));

        self::assertSame('https://traces.example.com:4318/', $capturedUrl);
    }
}
