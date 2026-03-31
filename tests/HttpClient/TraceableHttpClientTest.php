<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\TracedResponse;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableHttpClientTest extends TestCase
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

    public function testRequestCreatesClientSpan(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/users');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        self::assertSame('GET api.example.com', $spans[0]->getName());
    }

    public function testRequestAttributesRecorded(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('POST', 'https://api.example.com:8443/data');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame('POST', $attributes['http.request.method']);
        self::assertSame('https://api.example.com:8443/data', $attributes['url.full']);
        self::assertSame('api.example.com', $attributes['server.address']);
        self::assertSame(8443, $attributes['server.port']);
        self::assertSame('/data', $attributes['url.path']);
        self::assertSame('https', $attributes['url.scheme']);
    }

    public function testResponseStatusCodeRecorded(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('Created', ['http_code' => 201]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('POST', 'https://api.example.com/items');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(201, $attributes['http.response.status_code']);
    }

    public function testErrorResponseMarksSpanAsError(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/missing');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testServerErrorMarksSpanAsError(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/broken');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testTraceContextInjectedIntoHeaders(): void
    {
        $capturedOptions = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return new MockResponse('OK');
        });

        $client = new TraceableHttpClient($mockClient);
        $response = $client->request('GET', 'https://api.example.com/test');
        $response->getStatusCode();

        $headers = $capturedOptions['headers'] ?? [];
        $headerMap = [];
        foreach ($headers as $header) {
            if (\is_string($header) && str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $headerMap[strtolower(trim($key))] = trim($value);
            }
        }

        self::assertArrayHasKey('traceparent', $headerMap);
        self::assertMatchesRegularExpression('/^00-[a-f0-9]{32}-[a-f0-9]{16}-0[01]$/', $headerMap['traceparent']);
    }

    public function testSuccessfulResponseHasOkStatus(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/ok');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK'));
        $client = new TraceableHttpClient($mockClient);

        $newClient = $client->withOptions(['timeout' => 5]);

        self::assertNotSame($client, $newClient);
        self::assertInstanceOf(TraceableHttpClient::class, $newClient);
    }

    public function testCancelEndsSpan(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/cancel');
        $response->cancel();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
    }

    public function testExceptionDuringRequestRecordsErrorAndRethrows(): void
    {
        $mockClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('Connection refused');
        });
        $client = new TraceableHttpClient($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        try {
            $client->request('GET', 'https://api.example.com/fail');
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame('Connection refused', $spans[0]->getStatus()->getDescription());

            $events = $spans[0]->getEvents();
            self::assertNotEmpty($events);
            self::assertSame('exception', $events[0]->getName());
        }
    }

    public function testStreamUnwrapsTracedResponses(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('chunk1'),
            new MockResponse('chunk2'),
        ]);
        $client = new TraceableHttpClient($mockClient);

        $r1 = $client->request('GET', 'https://api.example.com/a');
        $r2 = $client->request('GET', 'https://api.example.com/b');

        self::assertInstanceOf(TracedResponse::class, $r1);
        self::assertInstanceOf(TracedResponse::class, $r2);

        $contents = [];
        $stream = $client->stream([$r1, $r2]);
        foreach ($stream as $response => $chunk) {
            if (!$chunk->isLast()) {
                $content = $chunk->getContent();
                if ($content !== '') {
                    $contents[] = $content;
                }
            }
        }

        self::assertNotEmpty($contents);
    }

    public function testStreamAcceptsSingleResponse(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('single'));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/single');
        self::assertInstanceOf(TracedResponse::class, $response);

        $stream = $client->stream($response);
        $content = '';
        foreach ($stream as $chunk) {
            if (!$chunk->isLast()) {
                $content .= $chunk->getContent();
            }
        }

        self::assertSame('single', $content);
    }

    public function testResetAllowsSubsequentRequests(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('first'),
            new MockResponse('second'),
        ]);
        $client = new TraceableHttpClient($mockClient);

        $r1 = $client->request('GET', 'https://api.example.com/a');
        self::assertSame('first', $r1->getContent());

        $client->reset();

        $r2 = $client->request('GET', 'https://api.example.com/b');
        self::assertSame('second', $r2->getContent());

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('GET api.example.com', $spans[0]->getName());
        self::assertSame('GET api.example.com', $spans[1]->getName());
    }

    public function testMalformedUrlUsesUnknownHost(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', '/relative-path');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('unknown', $attributes['server.address']);
    }

    public function testRequestWithoutPortOmitsServerPort(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('server.port', $attributes);
    }

    public function testReEntranceGuardPreventsRecursiveSpans(): void
    {
        $innerCalled = false;
        $outerClient = null;
        $depth = 0;

        $mockClient = new MockHttpClient(function () use (&$innerCalled, &$outerClient, &$depth) {
            $depth++;
            if ($depth === 1) {
                $innerResponse = $outerClient->request('GET', 'https://collector.example.com/export');
                $innerResponse->getContent();
                $innerCalled = true;
            }

            return new MockResponse('OK');
        });

        $outerClient = new TraceableHttpClient($mockClient);
        $response = $outerClient->request('GET', 'https://api.example.com/test');
        $response->getStatusCode();

        self::assertTrue($innerCalled);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans, 'Only the outer call should produce a span');
        self::assertSame('GET api.example.com', $spans[0]->getName());
    }

    public function testExcludedHostsSkipsTracing(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient, 'opentelemetry-symfony', ['collector.local']);

        $response = $client->request('POST', 'https://collector.local/v1/traces');
        $response->getContent();

        $spans = $this->exporter->getSpans();
        self::assertCount(0, $spans, 'Excluded host should not produce spans');
    }

    public function testExcludedHostsAreCaseInsensitive(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient, 'opentelemetry-symfony', ['Collector.LOCAL']);

        $response = $client->request('GET', 'https://collector.local/v1/traces');
        $response->getContent();

        $spans = $this->exporter->getSpans();
        self::assertCount(0, $spans);
    }

    public function testOtlpEndpointAutoExcluded(): void
    {
        $prev = $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? null;
        $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'https://otel.example.com:4318';

        try {
            $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
            $client = new TraceableHttpClient($mockClient);

            $response = $client->request('POST', 'https://otel.example.com:4318/v1/traces');
            $response->getContent();

            $spans = $this->exporter->getSpans();
            self::assertCount(0, $spans, 'OTLP endpoint should be auto-excluded');
        } finally {
            if (null === $prev) {
                unset($_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']);
            } else {
                $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] = $prev;
            }
        }
    }

    public function testNonExcludedHostStillTraced(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient, 'opentelemetry-symfony', ['collector.local']);

        $response = $client->request('GET', 'https://api.example.com/users');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('GET api.example.com', $spans[0]->getName());
    }

    public function testReEntranceGuardResetsAfterException(): void
    {
        $callCount = 0;
        $mockClient = new MockHttpClient(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('First call fails');
            }

            return new MockResponse('OK');
        });

        $client = new TraceableHttpClient($mockClient);

        try {
            $client->request('GET', 'https://api.example.com/fail');
        } catch (\RuntimeException) {
        }

        $response = $client->request('GET', 'https://api.example.com/ok');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans, 'Guard must reset after exception so next call is traced');
    }
}
