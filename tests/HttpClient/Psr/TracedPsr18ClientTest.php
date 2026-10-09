<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient\Psr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\TracingMiddleware;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\TracedPsr18Client;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TracedPsr18ClientTest extends TestCase
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
        unset($_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']);
        $this->tearDownOTel();
    }

    public function testSendRequestCreatesClientSpanWithRequiredAttributes(): void
    {
        $this->mock->append(new Response(201, ['Content-Length' => '12'], 'hello world!'));
        $client = $this->client();

        $response = $client->sendRequest(new Request('POST', 'https://api.example.com/users?token=abc'));

        self::assertSame(201, $response->getStatusCode());
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('POST', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('POST', $attrs['http.request.method']);
        self::assertSame('https://api.example.com/users?token=abc', $attrs['url.full']);
        self::assertSame('api.example.com', $attrs['server.address']);
        self::assertSame(443, $attrs['server.port']);
        self::assertSame('https', $attrs['url.scheme']);
        self::assertSame(201, $attrs['http.response.status_code']);
        self::assertSame('1.1', $attrs['network.protocol.version']);
        self::assertSame(12, $attrs['http.response.body.size']);
        self::assertArrayNotHasKey('url.path', $attrs);
        self::assertArrayNotHasKey('http.request.resend_count', $attrs);
    }

    public function testExplicitPortAndCredentialsRedaction(): void
    {
        $this->mock->append(new Response(200));

        $this->client()->sendRequest(new Request('GET', 'http://user:secret@internal.local:8080/x?sig=abc&q=1'));

        $attrs = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('http://REDACTED:REDACTED@internal.local:8080/x?sig=REDACTED&q=1', $attrs['url.full']);
        self::assertSame(8080, $attrs['server.port']);
    }

    public function testClientErrorMarksSpanAsError(): void
    {
        $this->mock->append(new Response(404));

        $this->client()->sendRequest(new Request('GET', 'https://api.example.com/missing'));

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('404', $span->getAttributes()->get('error.type'));
        self::assertSame(404, $span->getAttributes()->get('http.response.status_code'));
    }

    public function testTransportFailureRecordsErrorTypeAndRethrows(): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        $this->mock->append(new ConnectException('Connection refused', $request));

        try {
            $this->client()->sendRequest($request);
            self::fail('expected the exception to propagate');
        } catch (ConnectException) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(ConnectException::class, $span->getAttributes()->get('error.type'));
        self::assertArrayNotHasKey('http.response.status_code', $span->getAttributes()->toArray());
    }

    public function testTraceContextIsInjectedIntoTheOutgoingRequest(): void
    {
        $this->mock->append(new Response(200));
        $parent = Globals::tracerProvider()->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $this->client()->sendRequest(new Request('GET', 'https://api.example.com/'));
        } finally {
            $scope->detach();
            $parent->end();
        }

        $sent = $this->mock->getLastRequest();
        self::assertNotNull($sent);
        $traceparent = $sent->getHeaderLine('traceparent');
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $traceparent);

        $spans = $this->exporter->getSpans();
        self::assertStringContainsString($spans[0]->getContext()->getSpanId(), $traceparent);
        self::assertSame($parent->getContext()->getTraceId(), $spans[0]->getContext()->getTraceId());
    }

    public function testUnknownMethodIsNormalized(): void
    {
        $this->mock->append(new Response(200));

        $this->client()->sendRequest(new Request('PURGE', 'https://api.example.com/'));

        $span = $this->exporter->getSpans()[0];
        self::assertSame('HTTP', $span->getName());
        self::assertSame('_OTHER', $span->getAttributes()->get('http.request.method'));
        self::assertSame('PURGE', $span->getAttributes()->get('http.request.method_original'));
    }

    public function testExcludedHostsAndOtlpEndpointAreSkipped(): void
    {
        $this->mock->append(new Response(200), new Response(200), new Response(200));
        $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'https://collector.internal:4318';
        $client = new TracedPsr18Client($this->guzzle(), 'test', ['Status.Example.com']);

        $client->sendRequest(new Request('GET', 'https://status.example.com/health'));
        $client->sendRequest(new Request('POST', 'https://collector.internal:4318/v1/traces'));
        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('https://api.example.com/', $spans[0]->getAttributes()->get('url.full'));
    }

    public function testDoesNotDoubleTraceWhenTheInnerGuzzleStackCarriesTheMiddleware(): void
    {
        $this->mock->append(new Response(200));
        $stack = HandlerStack::create($this->mock);
        $stack->push(new TracingMiddleware('test'), TracingMiddleware::NAME);
        $client = new TracedPsr18Client(new Client(['handler' => $stack]), 'test');

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testDoesNotDoubleTraceOverATracedSymfonyHttpClient(): void
    {
        $symfony = new TraceableHttpClient(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])), 'test');
        $client = new TracedPsr18Client(new Psr18Client($symfony), 'test');

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testSymfonyHttpClientTracesAgainOnceThePsr18RequestIsDone(): void
    {
        $this->mock->append(new Response(200));
        $symfony = new TraceableHttpClient(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])), 'test');

        $this->client()->sendRequest(new Request('GET', 'https://api.example.com/'));
        $symfony->request('GET', 'https://api.example.com/')->getStatusCode();

        self::assertCount(2, $this->exporter->getSpans());
    }

    public function testResetKeepsTracing(): void
    {
        $this->mock->append(new Response(200), new Response(200));
        $client = $this->client();

        $client->sendRequest(new Request('GET', 'https://api.example.com/a'));
        $client->reset();
        $client->sendRequest(new Request('GET', 'https://api.example.com/b'));

        self::assertCount(2, $this->exporter->getSpans());
    }

    private function client(): TracedPsr18Client
    {
        return new TracedPsr18Client($this->guzzle(), 'test');
    }

    private function guzzle(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }
}
