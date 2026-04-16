<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\TracedResponse;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TracedResponseTest extends TestCase
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

    public function testGetInfoDelegatesToInnerResponse(): void
    {
        $response = $this->makeResponse(200, 'OK');

        $url = $response->getInfo('url');
        self::assertSame('https://api.example.com/test', $url);
    }

    public function testGetInnerResponseReturnsOriginal(): void
    {
        $response = $this->makeResponse(200, 'OK');

        $inner = $response->getInnerResponse();
        self::assertNotSame($response, $inner);
        self::assertNotInstanceOf(TracedResponse::class, $inner);
    }

    public function testGetSpanReturnsSpanInstance(): void
    {
        $response = $this->makeResponse(200, 'OK');

        self::assertInstanceOf(SpanInterface::class, $response->getSpan());
    }

    public function testGetContentFinalizesSpan(): void
    {
        $response = $this->makeResponse(200, 'hello');

        $content = $response->getContent();

        self::assertSame('hello', $content);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(200, $attributes['http.response.status_code']);
        self::assertSame(5, $attributes['http.response.body.size']);
    }

    public function testToArrayFinalizesSpan(): void
    {
        $body = '{"key":"value"}';
        $response = $this->makeResponse(200, $body, ['content-type' => 'application/json']);

        $array = $response->toArray();

        self::assertSame(['key' => 'value'], $array);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(200, $attributes['http.response.status_code']);
    }

    public function testGetHeadersFinalizesSpan(): void
    {
        $response = $this->makeResponse(200, 'OK', ['x-custom' => 'test']);

        $headers = $response->getHeaders();

        self::assertArrayHasKey('x-custom', $headers);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(200, $attributes['http.response.status_code']);
    }

    public function testGetHeadersRecordsBodySizeFromContentLength(): void
    {
        $response = $this->makeResponse(200, 'hello world', ['content-length' => '11']);

        $response->getHeaders();

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame(11, $attributes['http.response.body.size']);
    }

    public function testSpanEndedOnlyOnce(): void
    {
        $response = $this->makeResponse(200, 'OK');

        $response->getStatusCode();
        $response->getHeaders();
        $response->getContent();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
    }

    public function testErrorResponseSetsSpanError(): void
    {
        $response = $this->makeResponse(500, 'Internal Error');

        $response->getStatusCode();

        $spans = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testGetHeadersWithThrowFalseOnError(): void
    {
        $response = $this->makeResponse(500, 'error');

        $headers = $response->getHeaders(false);

        self::assertIsArray($headers);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testGetContentWithThrowFalseOnError(): void
    {
        $response = $this->makeResponse(500, 'error body');

        $content = $response->getContent(false);

        self::assertSame('error body', $content);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testToArrayWithThrowFalseOnError(): void
    {
        $body = '{"error":"fail"}';
        $response = $this->makeResponse(500, $body, ['content-type' => 'application/json']);

        $array = $response->toArray(false);

        self::assertSame(['error' => 'fail'], $array);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testGetHeadersThrowsOnErrorAndRecordsException(): void
    {
        $response = $this->makeResponse(404, 'Not Found');

        try {
            $response->getHeaders(true);
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertNotEmpty($spans[0]->getEvents());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testGetContentThrowsOnErrorAndRecordsException(): void
    {
        $response = $this->makeResponse(500, 'Server Error');

        try {
            $response->getContent(true);
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertNotEmpty($spans[0]->getEvents());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testCancelEndsSpan(): void
    {
        $response = $this->makeResponse(200, 'OK');

        $response->cancel();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
    }

    public function testToArrayThrowsOnErrorAndRecordsException(): void
    {
        $response = $this->makeResponse(500, '{"error":"fail"}', ['content-type' => 'application/json']);

        try {
            $response->toArray(true);
            self::fail('Expected exception');
        } catch (\Throwable) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertNotEmpty($spans[0]->getEvents());
        self::assertSame('exception', $spans[0]->getEvents()[0]->getName());
    }

    public function testGetContentEmptyBodyDoesNotSetBodySize(): void
    {
        $response = $this->makeResponse(204, '');

        $content = $response->getContent(false);

        self::assertSame('', $content);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertArrayNotHasKey('http.response.body.size', $spans[0]->getAttributes()->toArray());
    }

    public function testDestructFinalizesSpanIfNotEnded(): void
    {
        $response = $this->makeResponse(200, 'OK');
        unset($response);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeResponse(int $statusCode, string $body, array $headers = []): TracedResponse
    {
        $mockClient = new MockHttpClient(
            new MockResponse($body, ['http_code' => $statusCode, 'response_headers' => $headers]),
        );
        $client = new TraceableHttpClient($mockClient);
        $response = $client->request('GET', 'https://api.example.com/test');

        self::assertInstanceOf(TracedResponse::class, $response);

        return $response;
    }
}
