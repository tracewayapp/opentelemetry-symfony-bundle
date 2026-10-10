<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\InstrumentedPsr18Client;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;
use Traceway\OpenTelemetryBundle\HttpClient\ResendCountingHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

/**
 * Symfony HttpClient, Guzzle and PSR-18 are three implementations of one
 * convention. The same request through each must yield the same span (name,
 * status, attributes) and the same metric attributes. Every client reports a
 * real peer here, so a missing network.peer.* cannot pass unnoticed; only a
 * plain PSR-18 client, which exposes no transport at all, is compared without it.
 */
final class ClientParityTest extends TestCase
{
    use OTelTestTrait;

    private const CURL_HTTP_VERSION_1_1 = 2;
    private const PEER_ADDRESS = '93.184.216.34';
    private const PEER_PORT = 8443;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function requests(): iterable
    {
        yield 'success with explicit port and a redacted query' => [200, 'GET', 'https://api.example.com:8443/items?q=1&sig=secret'];
        yield 'client error' => [404, 'POST', 'http://api.example.com/missing'];
        yield 'server error' => [503, 'GET', 'https://api.example.com/down'];
        yield 'unknown method' => [200, 'PURGE', 'https://api.example.com/cache'];
    }

    #[DataProvider('requests')]
    public function testAllClientsEmitIdenticalTelemetry(int $status, string $method, string $url): void
    {
        $symfony = $this->observe(static function () use ($status, $method, $url): void {
            $mock = new MockHttpClient(new MockResponse('hello', [
                'http_code' => $status,
                'http_version' => self::CURL_HTTP_VERSION_1_1,
                'primary_ip' => self::PEER_ADDRESS,
                'primary_port' => self::PEER_PORT,
                'response_headers' => ['content-length' => '5'],
            ]));
            (new MeteredHttpClient(new TraceableHttpClient($mock, 'test'), 'test'))->request($method, $url)->getContent(false);
        });

        $guzzle = $this->observe(static function () use ($status, $method, $url): void {
            $response = new Response($status, ['Content-Length' => '5'], 'hello');
            $stack = HandlerStack::create(static function (RequestInterface $request, array $options) use ($response) {
                if (isset($options['on_stats'])) {
                    $options['on_stats'](new TransferStats($request, $response, 0.01, null, ['primary_ip' => self::PEER_ADDRESS, 'primary_port' => self::PEER_PORT]));
                }

                return Create::promiseFor($response);
            });
            $stack->push(new OpenTelemetryMiddleware('test', [], null, new RequestMeter('test')), OpenTelemetryMiddleware::NAME);
            (new Client(['handler' => $stack, 'http_errors' => false]))->request($method, $url);
        });

        $psr18 = $this->observe(static function () use ($status, $method, $url): void {
            $inner = new class($status) implements ClientInterface {
                public function __construct(private readonly int $status)
                {
                }

                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    return new Response($this->status, ['Content-Length' => '5'], 'hello');
                }
            };
            (new InstrumentedPsr18Client($inner, 'test', [], null, new RequestMeter('test')))->sendRequest(new Request($method, $url));
        });

        self::assertSame(self::PEER_ADDRESS, $symfony['attributes']['network.peer.address'] ?? null, 'the comparison must include a real peer');
        self::assertSame($symfony, $guzzle, 'Guzzle diverges from Symfony HttpClient');

        unset($symfony['attributes']['network.peer.address'], $symfony['attributes']['network.peer.port']);
        self::assertSame($symfony, $psr18, 'PSR-18 diverges from Symfony HttpClient beyond the peer a plain PSR-18 client cannot expose');
    }

    public function testRetriesAreOneSpanAndOneMeasurementPerAttemptOnEveryClient(): void
    {
        $symfony = $this->observeAttempts(static function (): void {
            $attempt = 0;
            $mock = new MockHttpClient(static function () use (&$attempt): MockResponse {
                return new MockResponse('', ['http_code' => ++$attempt < 3 ? 503 : 200, 'http_version' => self::CURL_HTTP_VERSION_1_1]);
            });
            // The order the container builds: counter, retry, meter, tracer, transport.
            $client = new ResendCountingHttpClient(new RetryableHttpClient(
                new MeteredHttpClient(new TraceableHttpClient($mock, 'test'), 'test'),
                new GenericRetryStrategy([503], 0),
                3,
            ));
            $client->request('GET', 'https://api.example.com/flaky')->getContent(false);
        });

        $guzzle = $this->observeAttempts(static function (): void {
            $stack = HandlerStack::create(new MockHandler([new Response(503), new Response(503), new Response(200)]));
            $stack->push(GuzzleMiddleware::retry(
                static fn (int $retries, RequestInterface $request, ?ResponseInterface $response = null): bool => $retries < 2 && 503 === $response?->getStatusCode(),
                static fn (): int => 0,
            ), 'retry');
            $stack->push(new OpenTelemetryMiddleware('test', [], null, new RequestMeter('test')), OpenTelemetryMiddleware::NAME);
            (new Client(['handler' => $stack, 'http_errors' => false]))->request('GET', 'https://api.example.com/flaky');
        });

        self::assertSame([
            'spans' => [[503, null], [503, 1], [200, 2]],
            'measurements' => ['200' => 1, '503' => 2],
        ], $symfony);
        self::assertSame($symfony, $guzzle);
    }

    /**
     * @return array{spans: list<array{mixed, mixed}>, measurements: array<string, int>}
     */
    private function observeAttempts(\Closure $send): array
    {
        $this->exporter->getStorage()->exchangeArray([]);
        $this->collectMetrics();

        $send();

        $spans = array_map(static fn ($span): array => [
            $span->getAttributes()->get('http.response.status_code'),
            $span->getAttributes()->get('http.request.resend_count'),
        ], $this->exporter->getSpans());

        $measurements = [];
        foreach ($this->collectMetrics()['http.client.request.duration']->data->dataPoints as $point) {
            $measurements[(string) $point->attributes->get('http.response.status_code')] = $point->count;
        }
        ksort($measurements);

        return ['spans' => $spans, 'measurements' => $measurements];
    }

    /**
     * @return array{name: string, status: string, attributes: array<string, mixed>, metrics: array<string, array<string, mixed>>}
     */
    private function observe(\Closure $send): array
    {
        $this->exporter->getStorage()->exchangeArray([]);
        $this->collectMetrics();

        $send();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        ksort($attributes);

        $metrics = [];
        foreach ($this->collectMetrics() as $name => $metric) {
            $points = [...$metric->data->dataPoints];
            self::assertCount(1, $points, $name);
            $pointAttributes = $points[0]->attributes->toArray();
            ksort($pointAttributes);
            $metrics[$name] = $pointAttributes;
        }
        ksort($metrics);

        return [
            'name' => $spans[0]->getName(),
            'status' => $spans[0]->getStatus()->getCode(),
            'attributes' => $attributes,
            'metrics' => $metrics,
        ];
    }
}
