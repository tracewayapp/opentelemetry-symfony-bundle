<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
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
        self::assertSame('GET', $spans[0]->getName());
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
        $mockClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions) {
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
                if ('' !== $content) {
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

    public function testStreamFinalizesSpanWhenConsumedToCompletion(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('hello', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/stream');
        self::assertInstanceOf(TracedResponse::class, $response);

        foreach ($client->stream($response) as $chunk) {
            $chunk->getContent();
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(200, $spans[0]->getAttributes()->toArray()['http.response.status_code']);
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
        self::assertSame('GET', $spans[0]->getName());
        self::assertSame('GET', $spans[1]->getName());
    }

    public function testRelativeUrlBackfillsServerAddressFromEffectiveUrl(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', '/relative-path');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        // The effective URL from transport info supplies the required server.address post-hoc.
        self::assertArrayHasKey('server.address', $attributes);
        self::assertArrayHasKey('url.full', $attributes);
        self::assertStringContainsString('/relative-path', $attributes['url.full']);
    }

    public function testDefaultPortInferredFromScheme(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(443, $attributes['server.port']);
    }

    public function testReEntranceGuardPreventsRecursiveSpans(): void
    {
        $innerCalled = false;
        $outerClient = null;
        $depth = 0;

        $mockClient = new MockHttpClient(static function () use (&$innerCalled, &$outerClient, &$depth) {
            ++$depth;
            if (1 === $depth) {
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
        self::assertSame('GET', $spans[0]->getName());
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
        self::assertSame('GET', $spans[0]->getName());
    }

    public function testReEntranceGuardResetsAfterException(): void
    {
        $callCount = 0;
        $mockClient = new MockHttpClient(static function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
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

    public function testStreamReKeysChunksToTracedResponse(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('{"ok":true}'));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/data');
        self::assertInstanceOf(TracedResponse::class, $response);

        $stream = $client->stream([$response]);
        foreach ($stream as $r => $chunk) {
            self::assertInstanceOf(TracedResponse::class, $r);
            self::assertSame($response, $r);
        }
    }

    public function testStreamWorksWithRetryableHttpClient(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('{"data":"value"}', ['http_code' => 200]));
        $traced = new TraceableHttpClient($mockClient);
        $retryable = new RetryableHttpClient($traced, maxRetries: 3);

        $response = $retryable->request('GET', 'https://api.example.com/json');
        $data = $response->toArray();

        self::assertSame(['data' => 'value'], $data);
    }

    public function testStreamWorksWithRetryableHttpClientGetContent(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('hello world', ['http_code' => 200]));
        $traced = new TraceableHttpClient($mockClient);
        $retryable = new RetryableHttpClient($traced, maxRetries: 3);

        $response = $retryable->request('GET', 'https://api.example.com/text');
        $content = $response->getContent();

        self::assertSame('hello world', $content);
    }

    public function testUnknownMethodNormalizedToOther(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('PROPFIND', 'https://api.example.com/dav');
        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame('HTTP', $spans[0]->getName());
        self::assertSame('_OTHER', $attributes['http.request.method']);
        self::assertSame('PROPFIND', $attributes['http.request.method_original']);
    }

    public function testUrlCredentialsRedacted(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://user:hunter2@api.example.com/users');
        $response->getStatusCode();

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('https://REDACTED:REDACTED@api.example.com/users', $attributes['url.full']);
    }

    public function testErrorStatusSetsErrorType(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/missing');
        $response->getStatusCode();

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('404', $span->getAttributes()->toArray()['error.type']);
    }

    public function testTransportExceptionSetsErrorType(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $client = new TraceableHttpClient($mockClient);

        $response = $client->request('GET', 'https://api.example.com/down');

        try {
            $response->getStatusCode();
        } catch (\Throwable) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertArrayHasKey('error.type', $span->getAttributes()->toArray());
    }

    public function testSyncFailureWithBaseUriKeepsServerAttributes(): void
    {
        $inner = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('DNS failure');
        });
        $client = (new TraceableHttpClient($inner, 'test'))->withOptions(['base_uri' => 'https://api.example.com/v2/']);

        try {
            $client->request('GET', '/users');
            self::fail('Expected exception');
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
        }

        $attr = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('api.example.com', $attr['server.address'], 'Required attribute must survive a pre-transport failure on relative URLs');
        self::assertSame(443, $attr['server.port']);
        self::assertSame('https://api.example.com/users', $attr['url.full']);
    }
}
