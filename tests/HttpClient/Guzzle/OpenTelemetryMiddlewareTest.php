<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OpenTelemetryMiddlewareTest extends TestCase
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

    public function testRequestCreatesClientSpan(): void
    {
        $this->mock->append(new Response(200, ['Content-Length' => '2'], 'ok'));

        $this->client()->get('https://api.example.com/items');

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('GET', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('https://api.example.com/items', $attrs['url.full']);
        self::assertSame('api.example.com', $attrs['server.address']);
        self::assertSame(443, $attrs['server.port']);
        self::assertSame(200, $attrs['http.response.status_code']);
        self::assertSame(2, $attrs['http.response.body.size']);
    }

    public function testInjectsTraceContextHeader(): void
    {
        $this->mock->append(new Response(200));

        $this->client()->get('https://api.example.com/');

        $sent = $this->mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $sent->getHeaderLine('traceparent'));
    }

    public function testRedirectProducesOneSpanPerAttemptWithResendCount(): void
    {
        $this->mock->append(
            new Response(302, ['Location' => 'https://api.example.com/final']),
            new Response(200),
        );

        $this->client()->get('https://api.example.com/start');

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('https://api.example.com/start', $spans[0]->getAttributes()->get('url.full'));
        self::assertSame(302, $spans[0]->getAttributes()->get('http.response.status_code'));
        self::assertArrayNotHasKey('http.request.resend_count', $spans[0]->getAttributes()->toArray());
        self::assertSame('https://api.example.com/final', $spans[1]->getAttributes()->get('url.full'));
        self::assertSame(1, $spans[1]->getAttributes()->get('http.request.resend_count'));
    }

    public function testRetryProducesOneSpanPerAttemptWithResendCount(): void
    {
        $this->mock->append(new Response(503), new Response(503), new Response(200));
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::retry(
            static fn (int $retries, $request, ?ResponseInterface $response = null): bool => $retries < 2 && null !== $response && 503 === $response->getStatusCode(),
            static fn (): int => 0,
        ), 'retry');
        $stack->push(new OpenTelemetryMiddleware('test'), OpenTelemetryMiddleware::NAME);

        (new Client(['handler' => $stack]))->get('https://api.example.com/flaky');

        $spans = $this->exporter->getSpans();
        self::assertCount(3, $spans);
        self::assertArrayNotHasKey('http.request.resend_count', $spans[0]->getAttributes()->toArray());
        self::assertSame(1, $spans[1]->getAttributes()->get('http.request.resend_count'));
        self::assertSame(2, $spans[2]->getAttributes()->get('http.request.resend_count'));
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_UNSET, $spans[2]->getStatus()->getCode());
    }

    public function testClientErrorIsRecordedFromTheResponseBeforeHttpErrorsThrows(): void
    {
        $this->mock->append(new Response(404));

        try {
            $this->client()->get('https://api.example.com/missing');
            self::fail('expected ClientException');
        } catch (ClientException) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(404, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame('404', $span->getAttributes()->get('error.type'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public function testTransportFailureRejectsAndRecordsError(): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        $this->mock->append(new ConnectException('Connection refused', $request));

        try {
            $this->client()->send($request);
            self::fail('expected ConnectException');
        } catch (ConnectException) {
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(ConnectException::class, $span->getAttributes()->get('error.type'));
    }

    public function testHandlerThatThrowsSynchronouslyStillEndsTheSpan(): void
    {
        $stack = new HandlerStack(static function (): never {
            throw new \InvalidArgumentException('SSL CA bundle not found');
        });
        $stack->push(new OpenTelemetryMiddleware('test'), OpenTelemetryMiddleware::NAME);

        try {
            (new Client(['handler' => $stack]))->send(new Request('GET', 'https://api.example.com/'));
            self::fail('expected the handler exception to propagate');
        } catch (\InvalidArgumentException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame(\InvalidArgumentException::class, $spans[0]->getAttributes()->get('error.type'));
    }

    public function testPeerAddressAndPortComeFromTheTransferStats(): void
    {
        $span = $this->sendThroughStatsReportingHandler(new Response(200), ['primary_ip' => '93.184.216.34', 'primary_port' => 443]);

        self::assertSame('93.184.216.34', $span->getAttributes()->get('network.peer.address'));
        self::assertSame(443, $span->getAttributes()->get('network.peer.port'));
    }

    public function testPeerIsRecordedWhenTheTransportFails(): void
    {
        $request = new Request('GET', 'https://api.example.com/');
        $handler = static function (Request $request, array $options) {
            $options['on_stats'](new TransferStats($request, null, 0.01, 'connection reset', ['primary_ip' => '10.0.0.7', 'primary_port' => 8443]));

            return Create::rejectionFor(new ConnectException('connection reset', $request));
        };
        $stack = new HandlerStack($handler);
        $stack->push(new OpenTelemetryMiddleware('test'), OpenTelemetryMiddleware::NAME);

        try {
            (new Client(['handler' => $stack]))->send($request);
            self::fail('expected ConnectException');
        } catch (ConnectException) {
        }

        $attrs = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('10.0.0.7', $attrs['network.peer.address']);
        self::assertSame(8443, $attrs['network.peer.port']);
        self::assertSame(ConnectException::class, $attrs['error.type']);
    }

    public function testTheApplicationsOwnOnStatsCallbackStillRuns(): void
    {
        $seen = [];
        $this->sendThroughStatsReportingHandler(new Response(200), ['primary_ip' => '127.0.0.1', 'primary_port' => 80], static function (TransferStats $stats) use (&$seen): void {
            $seen[] = $stats->getHandlerStat('primary_ip');
        });

        self::assertSame(['127.0.0.1'], $seen);
    }

    public function testNoPeerWhenTheHandlerReportsNone(): void
    {
        $this->mock->append(new Response(200));

        $this->client()->get('https://api.example.com/');

        self::assertArrayNotHasKey('network.peer.address', $this->exporter->getSpans()[0]->getAttributes()->toArray());
    }

    public function testAsyncRequestEndsSpanWhenThePromiseResolves(): void
    {
        $this->mock->append(new Response(200));

        $promise = $this->client()->getAsync('https://api.example.com/async');
        self::assertCount(0, $this->exporter->getSpans());
        $promise->wait();

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testExcludedHostIsPassedThrough(): void
    {
        $this->mock->append(new Response(200));
        $stack = HandlerStack::create($this->mock);
        $stack->push(new OpenTelemetryMiddleware('test', ['api.example.com']), OpenTelemetryMiddleware::NAME);

        (new Client(['handler' => $stack]))->get('https://api.example.com/');

        self::assertCount(0, $this->exporter->getSpans());
    }

    /**
     * @param array<string, mixed> $handlerStats
     */
    private function sendThroughStatsReportingHandler(Response $response, array $handlerStats, ?\Closure $onStats = null): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        $handler = static function (Request $request, array $options) use ($response, $handlerStats) {
            $options['on_stats'](new TransferStats($request, $response, 0.01, null, $handlerStats));

            return Create::promiseFor($response);
        };
        $stack = new HandlerStack($handler);
        $stack->push(new OpenTelemetryMiddleware('test'), OpenTelemetryMiddleware::NAME);

        (new Client(['handler' => $stack, 'on_stats' => $onStats]))->get('https://api.example.com/');

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        return $spans[0];
    }

    private function client(): Client
    {
        return new Client(['handler' => $this->stack()]);
    }

    private function stack(): HandlerStack
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(new OpenTelemetryMiddleware('test'), OpenTelemetryMiddleware::NAME);

        return $stack;
    }
}
