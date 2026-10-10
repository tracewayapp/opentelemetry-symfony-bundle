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

    public function testGrpcEndpointIsProbedOverTcpAndReportedReachable(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, $error);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        try {
            $result = (new OtlpEndpointReachabilityCheck())->run(CheckTestHelper::context([
                'OTEL_EXPORTER_OTLP_ENDPOINT' => '127.0.0.1:'.$port,
                'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
            ]));
        } finally {
            fclose($server);
        }

        self::assertSame(Status::Ok, $result->status);
        self::assertStringContainsString('gRPC endpoint reachable (TCP 127.0.0.1:'.$port, $result->message);
        self::assertSame($port, $result->details['port']);
    }

    public function testGrpcEndpointThatRefusesTheConnectionIsAnError(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, $error);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $result = (new OtlpEndpointReachabilityCheck())->run(CheckTestHelper::context(
            ['OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:'.$port, 'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc'],
            networkTimeoutSeconds: 0.5,
        ));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('OTLP gRPC endpoint unreachable', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testGrpcEndpointWithoutAPortDefaultsTo4317(): void
    {
        $result = (new OtlpEndpointReachabilityCheck())->run(CheckTestHelper::context(
            ['OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1', 'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc'],
            networkTimeoutSeconds: 0.5,
        ));

        self::assertSame(4317, $result->details['port']);
    }

    public function testUnparsableGrpcEndpointIsAnError(): void
    {
        $result = (new OtlpEndpointReachabilityCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http:///nohost',
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
        ]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('Cannot parse gRPC endpoint', $result->message);
    }
}
