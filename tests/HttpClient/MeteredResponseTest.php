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

    public function testCancelMarksResponseFinalisedSilently(): void
    {
        $response = $this->wrap(new MockResponse('ok', ['http_code' => 200]));

        $response->cancel();

        // No metric emitted because the response is marked finalized before any record call.
        self::assertSame([], $this->collectMetrics());
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

    private function wrap(MockResponse $mock): MeteredResponse
    {
        $client = new MeteredHttpClient(new MockHttpClient($mock), 'test');

        $response = $client->request('GET', 'https://api.example.com/x');
        self::assertInstanceOf(MeteredResponse::class, $response);

        return $response;
    }
}
