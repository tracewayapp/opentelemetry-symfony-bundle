<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredResponse;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class MeteredHttpClientTest extends TestCase
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

    public function testRequestEmitsDurationWithRequiredAttributes(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('GET', 'https://api.example.com:8443/users');
        $response->getStatusCode();

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('http.client.request.duration', $metrics);
        $duration = $metrics['http.client.request.duration'];
        self::assertSame('s', $duration->unit);

        $points = [...$duration->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame(1, $points[0]->count);

        $attr = $points[0]->attributes->toArray();
        self::assertSame('GET', $attr['http.request.method']);
        self::assertSame('api.example.com', $attr['server.address']);
        self::assertSame(8443, $attr['server.port']);
        self::assertSame('https', $attr['url.scheme']);
        self::assertSame(200, $attr['http.response.status_code']);
        self::assertArrayNotHasKey('error.type', $attr);
    }

    public function testResponseBodySizeFromHeaders(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('payload', [
            'http_code' => 200,
            'response_headers' => ['Content-Length: 7'],
        ]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getHeaders();

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.client.response.body.size', $metrics);

        $points = [...$metrics['http.client.response.body.size']->data->dataPoints];
        self::assertSame(7, $points[0]->sum);
    }

    public function testRequestBodySizeFromContentLengthHeader(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 204]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('POST', 'https://api.example.com/items', [
            'body' => '{"name":"foo"}',
            'headers' => ['Content-Length' => '14'],
        ]);
        $response->getStatusCode();

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.client.request.body.size', $metrics);

        $points = [...$metrics['http.client.request.body.size']->data->dataPoints];
        self::assertSame(14, $points[0]->sum);
    }

    public function testRequestBodySizeFromStringBodyWhenNoHeader(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 204]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('POST', 'https://api.example.com/items', [
            'body' => 'hello',
        ]);
        $response->getStatusCode();

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.client.request.body.size']->data->dataPoints];
        self::assertSame(5, $points[0]->sum);
    }

    public function testNoRequestBodySizeWhenNothingMeasurable(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getStatusCode();

        $metrics = $this->collectMetrics();
        self::assertArrayNotHasKey('http.client.request.body.size', $metrics);
    }

    public function testErrorStatusCodeRecordedWithoutErrorType(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('not found', ['http_code' => 404]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('GET', 'https://api.example.com/missing');
        $response->getStatusCode();

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.client.request.duration']->data->dataPoints];
        $attr = $points[0]->attributes->toArray();

        self::assertSame(404, $attr['http.response.status_code']);
        // A 4xx response is a successful transport: we don't synthesize error.type here.
        // Users can alert on status_code >= 400 in Grafana.
        self::assertArrayNotHasKey('error.type', $attr);
    }

    public function testTransportFailureAddsErrorType(): void
    {
        $mockClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });
        $client = new MeteredHttpClient($mockClient, 'test');

        try {
            $response = $client->request('GET', 'https://api.example.com/data');
            $response->getContent();
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.client.request.duration']->data->dataPoints];
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);
    }

    public function testExcludedHostSkipsMetrics(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $client = new MeteredHttpClient($mockClient, 'test', ['api.example.com']);

        $response = $client->request('GET', 'https://api.example.com/data');
        $response->getStatusCode();

        self::assertSame([], $this->collectMetrics());
    }

    public function testOtlpEndpointAutoExcluded(): void
    {
        $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'https://otlp.example.com';

        try {
            $mockClient = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
            $client = new MeteredHttpClient($mockClient, 'test');

            $response = $client->request('POST', 'https://otlp.example.com/v1/metrics');
            $response->getStatusCode();

            self::assertSame([], $this->collectMetrics());
        } finally {
            unset($_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']);
        }
    }

    public function testResponseReturnsMeteredResponse(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $response = $client->request('GET', 'https://api.example.com/data');

        self::assertInstanceOf(MeteredResponse::class, $response);
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('ok', ['http_code' => 200]));
        $client = new MeteredHttpClient($mockClient, 'test');

        $clone = $client->withOptions(['timeout' => 10]);

        self::assertInstanceOf(MeteredHttpClient::class, $clone);
        self::assertNotSame($client, $clone);
    }

    public function testStreamUnwrapsMeteredResponses(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('a', ['http_code' => 200]),
            new MockResponse('b', ['http_code' => 200]),
        ]);
        $client = new MeteredHttpClient($mockClient, 'test');

        $r1 = $client->request('GET', 'https://api.example.com/a');
        $r2 = $client->request('GET', 'https://api.example.com/b');

        $chunks = 0;
        foreach ($client->stream([$r1, $r2]) as $response => $chunk) {
            if ($chunk->isLast()) {
                $chunks++;
            }
        }

        self::assertSame(2, $chunks);
    }

    public function testResetClearsCachedMeter(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('ok', ['http_code' => 200]),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client = new MeteredHttpClient($mockClient, 'test');

        $client->request('GET', 'https://api.example.com/a')->getStatusCode();
        $client->reset();
        $client->request('GET', 'https://api.example.com/b')->getStatusCode();

        $metrics = $this->collectMetrics();
        $points = [...$metrics['http.client.request.duration']->data->dataPoints];
        $total = 0;
        foreach ($points as $p) {
            $total += $p->count;
        }
        self::assertSame(2, $total);
    }
}
