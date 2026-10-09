<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient\Psr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\TracingMiddleware;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\TracedPsr18Client;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class RequestMeterTest extends TestCase
{
    use OTelTestTrait;

    private MockHandler $mock;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->mock = new MockHandler();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testPsr18ClientRecordsDurationAndBodySizesWithRequiredAttributes(): void
    {
        $this->mock->append(new Response(201, ['Content-Length' => '5'], 'hello'));
        $client = new TracedPsr18Client($this->guzzle(), 'test', [], null, new RequestMeter('test'));

        $client->sendRequest(new Request('POST', 'https://api.example.com:8443/items', ['Content-Length' => '3'], 'abc'));

        $metrics = $this->collectMetrics();
        $duration = [...$metrics['http.client.request.duration']->data->dataPoints];
        self::assertCount(1, $duration);
        self::assertSame(1, $duration[0]->count);
        $attrs = $duration[0]->attributes->toArray();
        self::assertSame('POST', $attrs['http.request.method']);
        self::assertSame('api.example.com', $attrs['server.address']);
        self::assertSame(8443, $attrs['server.port']);
        self::assertSame('https', $attrs['url.scheme']);
        self::assertSame(201, $attrs['http.response.status_code']);
        self::assertSame('1.1', $attrs['network.protocol.version']);
        self::assertArrayNotHasKey('error.type', $attrs);
        self::assertSame(3.0, (float) [...$metrics['http.client.request.body.size']->data->dataPoints][0]->sum);
        self::assertSame(5.0, (float) [...$metrics['http.client.response.body.size']->data->dataPoints][0]->sum);
    }

    public function testGuzzleMiddlewareRecordsOncePerAttempt(): void
    {
        $this->mock->append(new Response(302, ['Location' => 'https://api.example.com/final']), new Response(200));
        $stack = HandlerStack::create($this->mock);
        $stack->push(new TracingMiddleware('test', [], null, new RequestMeter('test')), TracingMiddleware::NAME);

        (new Client(['handler' => $stack]))->get('https://api.example.com/start');

        $points = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints];
        $statuses = array_map(static fn ($p) => $p->attributes->get('http.response.status_code'), $points);
        sort($statuses);
        self::assertSame([200, 302], $statuses);
    }

    public function testGuzzleInsideADecoratedPsr18ClientIsCountedOnce(): void
    {
        $this->mock->append(new Response(200));
        $meter = new RequestMeter('test');
        $stack = HandlerStack::create($this->mock);
        $stack->push(new TracingMiddleware('test', [], null, $meter), TracingMiddleware::NAME);
        $client = new TracedPsr18Client(new Client(['handler' => $stack]), 'test', [], null, $meter);

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        $points = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame(1, $points[0]->count);
        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testTransportFailureRecordsErrorType(): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        $this->mock->append(new ConnectException('refused', $request));
        $stack = HandlerStack::create($this->mock);
        $stack->push(new TracingMiddleware('test', [], null, new RequestMeter('test')), TracingMiddleware::NAME);

        try {
            (new Client(['handler' => $stack]))->send($request);
        } catch (ConnectException) {
        }

        $attrs = [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame(ConnectException::class, $attrs['error.type']);
        self::assertArrayNotHasKey('http.response.status_code', $attrs);
    }

    public function testMetersEvenWhenTracingExcludesTheHost(): void
    {
        $client = new TracedPsr18Client(new PlainPsr18ClientReturning200(), 'test', ['api.example.com'], null, new RequestMeter('test'));

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        self::assertCount(0, $this->exporter->getSpans());
        self::assertCount(1, [...$this->collectMetrics()['http.client.request.duration']->data->dataPoints]);
    }

    public function testMetricExcludedHostIsNotMetered(): void
    {
        $client = new TracedPsr18Client(new PlainPsr18ClientReturning200(), 'test', [], null, new RequestMeter('test', ['api.example.com']));

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        self::assertCount(1, $this->exporter->getSpans());
        self::assertArrayNotHasKey('http.client.request.duration', $this->collectMetrics());
    }

    private function guzzle(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }
}

final class PlainPsr18ClientReturning200 implements \Psr\Http\Client\ClientInterface
{
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new Response(200);
    }
}
