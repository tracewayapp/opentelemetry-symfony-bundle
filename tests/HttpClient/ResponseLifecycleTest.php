<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * The ways a Symfony HttpClient response ends besides getContent(): consumed
 * as a stream, abandoned after its headers, cancelled, failing mid-body, or
 * sent through base_uri. Each must still finish the span and the measurement,
 * as the metered and traced decorators are stacked in the container.
 */
final class ResponseLifecycleTest extends TestCase
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

    public function testToStreamFinishesSpanAndMeasurement(): void
    {
        $client = $this->client(new MockResponse('stream-body', ['http_code' => 200, 'response_headers' => ['content-length' => '11']]));

        $stream = $client->request('GET', 'https://api.example.com/s')->toStream();

        self::assertSame('stream-body', stream_get_contents($stream));
        $span = $this->onlySpan();
        self::assertSame(200, $span['attributes']['http.response.status_code']);
        self::assertSame(11, $span['attributes']['http.response.body.size']);
        self::assertSame(200, $this->onlyDurationPoint()['http.response.status_code']);
    }

    public function testToStreamThrowingOnAServerErrorRecordsTheStatusAndTheException(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 500]));

        try {
            $client->request('GET', 'https://api.example.com/e')->toStream(true);
            self::fail('expected ServerException');
        } catch (ServerException) {
        }

        $span = $this->onlySpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span['status']);
        self::assertSame(500, $span['attributes']['http.response.status_code']);
        self::assertSame(ServerException::class, $span['attributes']['error.type']);
        self::assertSame(ServerException::class, $this->onlyDurationPoint()['error.type']);
    }

    public function testResponseDroppedAfterItsHeadersIsFinishedWithTheReceivedStatus(): void
    {
        $client = $this->client(new MockResponse(['part-one', 'part-two'], ['http_code' => 202]));

        $response = $client->request('GET', 'https://api.example.com/partial');
        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isFirst()) {
                break;
            }
        }
        unset($response, $chunk);
        gc_collect_cycles();

        self::assertSame(202, $this->onlySpan()['attributes']['http.response.status_code']);
        self::assertSame(202, $this->onlyDurationPoint()['http.response.status_code']);
    }

    public function testResponseDroppedBeforeAnythingArrivedHasNoStatus(): void
    {
        $client = $this->client(new MockResponse('x', ['http_code' => 201]));

        $response = $client->request('GET', 'https://api.example.com/abandoned');
        unset($response);
        gc_collect_cycles();

        self::assertArrayNotHasKey('http.response.status_code', $this->onlySpan()['attributes'], 'nothing was received, so no status is invented');
    }

    public function testCancelRecordsCancelledOnSpanAndMeasurement(): void
    {
        $client = $this->client(new MockResponse('body', ['http_code' => 200]));

        $client->request('GET', 'https://api.example.com/c')->cancel();

        self::assertSame('cancelled', $this->onlySpan()['attributes']['error.type']);
        self::assertSame('cancelled', $this->onlyDurationPoint()['error.type']);
    }

    public function testTransportErrorAfterTheHeadersKeepsTheReceivedStatus(): void
    {
        $client = $this->client(new MockResponse([new \RuntimeException('connection reset')]));
        $response = $client->request('GET', 'https://api.example.com/t');

        try {
            foreach ($client->stream($response) as $chunk) {
                $chunk->getContent();
            }
            self::fail('expected TransportException');
        } catch (TransportException) {
        }

        $span = $this->onlySpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span['status']);
        self::assertSame(200, $span['attributes']['http.response.status_code']);
        self::assertSame(TransportException::class, $span['attributes']['error.type']);
        self::assertSame(TransportException::class, $this->onlyDurationPoint()['error.type']);
    }

    public function testRelativeUrlIsRecordedAsTheAbsoluteUrlActuallySent(): void
    {
        $client = $this->client(new MockResponse('ok'))->withOptions(['base_uri' => 'https://api.example.com:8443/v1/']);

        $client->request('GET', 'items')->getContent();

        $span = $this->onlySpan();
        self::assertSame('https://api.example.com:8443/v1/items', $span['attributes']['url.full']);
        self::assertSame(8443, $span['attributes']['server.port']);
        self::assertSame(8443, $this->onlyDurationPoint()['server.port']);
    }

    private function client(MockResponse $response): HttpClientInterface
    {
        return new MeteredHttpClient(new TraceableHttpClient(new MockHttpClient($response), 'test'), 'test');
    }

    /** @return array{status: string, attributes: array<string, mixed>} */
    private function onlySpan(): array
    {
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        return ['status' => $spans[0]->getStatus()->getCode(), 'attributes' => $spans[0]->getAttributes()->toArray()];
    }

    /** @return array<string, mixed> */
    private function onlyDurationPoint(): array
    {
        $points = [...($this->collectMetrics()['http.client.request.duration'] ?? null)?->data->dataPoints ?? []];
        self::assertCount(1, $points);

        return $points[0]->attributes->toArray();
    }
}
