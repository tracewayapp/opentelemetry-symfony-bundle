<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
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
}
