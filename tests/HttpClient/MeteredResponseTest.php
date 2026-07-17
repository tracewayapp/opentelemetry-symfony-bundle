<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredResponse;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class MeteredResponseTest extends TestCase
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

    public function testGetHeadersFinalisesWithBodySize(): void
    {
        $body = str_repeat('x', 123);
        $response = $this->wrap(new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['Content-Length: 123'],
        ]));

        $headers = $response->getHeaders();

        self::assertArrayHasKey('content-length', $headers);

        $bodySize = [...$this->collectMetrics()['http.client.response.body.size']->data->dataPoints][0]->sum;
        self::assertSame(123, $bodySize);
    }

    public function testGetHeadersFinalisesWithoutBodySizeWhenContentLengthMissing(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));

        $response->getHeaders();

        $metrics = $this->collectMetrics();
        self::assertArrayNotHasKey('http.client.response.body.size', $metrics);
    }

    public function testGetHeadersFinalisesWithErrorOnTransportFailure(): void
    {
        $response = $this->wrap(new MockResponse('', [
            'http_code' => 0,
            'error' => 'connection refused',
        ]));

        try {
            $response->getHeaders();
            self::fail('Expected TransportException');
        } catch (TransportException) {
        }

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertArrayHasKey('error.type', $attr);
    }

    public function testGetContentFinalisesWithBodySize(): void
    {
        $response = $this->wrap(new MockResponse('hello world', ['http_code' => 200]));

        $content = $response->getContent();

        self::assertSame('hello world', $content);

        $bodySize = [...$this->collectMetrics()['http.client.response.body.size']->data->dataPoints][0]->sum;
        self::assertSame(11, $bodySize);
    }

    public function testGetContentFinalisesWithErrorOnFailure(): void
    {
        $response = $this->wrap(new MockResponse('', [
            'http_code' => 0,
            'error' => 'transport down',
        ]));

        try {
            $response->getContent();
            self::fail('Expected TransportException');
        } catch (TransportException) {
        }

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertArrayHasKey('error.type', $attr);
    }

    public function testToArrayFinalisesOnSuccess(): void
    {
        $response = $this->wrap(new MockResponse(json_encode(['ok' => true]), [
            'http_code' => 200,
            'response_headers' => ['Content-Type: application/json'],
        ]));

        $array = $response->toArray();

        self::assertSame(['ok' => true], $array);
        self::assertArrayHasKey('http.client.request.duration', $this->collectMetrics());
    }

    public function testToArrayFinalisesWithErrorOnTransportFailure(): void
    {
        $response = $this->wrap(new MockResponse('', [
            'http_code' => 0,
            'error' => 'down',
        ]));

        try {
            $response->toArray();
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertArrayHasKey('error.type', $attr);
    }

    public function testGetStatusCodeFinalisesWithErrorOnTransportFailure(): void
    {
        $response = $this->wrap(new MockResponse('', [
            'http_code' => 0,
            'error' => 'connection refused',
        ]));

        try {
            $response->getStatusCode();
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertArrayHasKey('error.type', $attr, 'a failure surfacing via getStatusCode() must still record a failure metric');
    }

    public function testProtocolVersionRecordedWhenTransportReportsIt(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200, 'http_version' => 2]));

        $response->getStatusCode();

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('1.1', $attr['network.protocol.version'], 'CURL_HTTP_VERSION_1_1 (int 2) normalizes to "1.1"');
    }

    public function testThrowingAccessorKeepsReceivedStatusCode(): void
    {
        $response = $this->wrap(new MockResponse('err', ['http_code' => 500]));

        try {
            $response->getContent(true);
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $attr = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame(500, $attr['http.response.status_code'], 'a status was received, so semconv requires it even on the throwing path');
        self::assertArrayHasKey('error.type', $attr);
    }

    public function testCancelRecordsDurationWithCancelledErrorType(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));

        $response->cancel();

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.client.request.duration', $metrics);
        $attr = [...$metrics['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame('cancelled', $attr['error.type']);

        // A second touch must not double-record.
        $response->cancel();
        $after = $this->collectMetrics();
        self::assertSame([], [...($after['http.client.request.duration']->data->dataPoints ?? [])]);
    }

    public function testGetInfoIsPassThrough(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 201]));

        // Trigger the underlying HTTP call so MockResponse populates info.
        $response->getStatusCode();

        self::assertSame(201, $response->getInfo('http_code'));
    }

    public function testGetInnerResponseExposesUnwrapped(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));

        $inner = $response->getInnerResponse();
        self::assertInstanceOf(ResponseInterface::class, $inner);
        self::assertNotInstanceOf(MeteredResponse::class, $inner);
    }

    public function testStatusCodeFinalisesOnce(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));

        $response->getStatusCode();
        $response->getStatusCode();

        $points = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints];
        self::assertSame(1, $points[0]->count);
    }

    public function testDestructorSkipsNeverStartedResponse(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));
        unset($response);

        // The destructor must not force a blocking network wait: a response
        // whose status was never received is dropped from metrics instead.
        self::assertArrayNotHasKey('http.client.request.duration', $this->collectMetrics());
    }

    public function testDestructorFinalisesReceivedResponse(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));
        $response->getHeaders();
        unset($response);

        self::assertArrayHasKey('http.client.request.duration', $this->collectMetrics());
    }

    public function testStackedTracedResponseCanStream(): void
    {
        $inner = new class implements ResponseInterface, \Symfony\Component\HttpClient\Response\StreamableInterface {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return 'ok';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null === $type ? [] : null;
            }

            /** @return resource */
            public function toStream(bool $throw = true)
            {
                $stream = fopen('php://memory', 'r');
                \assert(false !== $stream);

                return $stream;
            }
        };

        $recorder = new MeteredHttpClient(new MockHttpClient(), 'test');
        $metered = new MeteredResponse($inner, $recorder, hrtime(true), ['http.request.method' => 'GET']);

        $span = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test')->spanBuilder('HTTP GET')->startSpan();
        $traced = new \Traceway\OpenTelemetryBundle\HttpClient\TracedResponse($metered, $span);

        // Regression: with metrics + tracing stacked, toStream() used to throw LogicException.
        $stream = $traced->toStream();
        self::assertIsResource($stream);

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('http.client.request.duration', $metrics);
        $attr = [...$metrics['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertArrayNotHasKey('error.type', $attr);
    }

    private function wrap(MockResponse $mock): MeteredResponse
    {
        $client = new MeteredHttpClient(new MockHttpClient($mock), 'test');

        $response = $client->request('GET', 'https://api.example.com/x');
        self::assertInstanceOf(MeteredResponse::class, $response);

        return $response;
    }
}
