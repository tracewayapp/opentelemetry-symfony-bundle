<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Soak;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

/**
 * Serves requests in one process the way RoadRunner, FrankenPHP or a Messenger
 * worker does, every instrumentation on, and holds the bundle to three things:
 * each operation yields exactly one span, one log record and one metric point,
 * no context scope is left open, and memory stops growing after warm-up.
 */
#[Group('soak')]
final class WorkerSoakTest extends TestCase
{
    use OTelTestTrait;

    private const WARMUP = 200;
    private const MEASURED = 400;
    private const MAX_GROWTH_BYTES_PER_REQUEST = 32;

    private const EXPECTED_SPANS = [
        'GET', 'GET', 'GET', 'GET', 'GET',
        'SELECT items', 'UPDATE items',
        'cache.get',
        'process SoakMessage', 'send SoakMessage',
        'twig.render page', 'twig.render part',
    ];

    private string $dir;
    private ?SoakKernel $kernel = null;

    /** @var callable|null */
    private mixed $previousExceptionHandler = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $this->previousExceptionHandler = set_exception_handler(null);
        restore_exception_handler();

        $this->setUpOTel();
        $this->dir = sys_get_temp_dir().'/otel_soak_'.getmypid().'_'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove($this->dir ?? '');
        if (isset($this->exporter)) {
            $this->tearDownOTel();
        }
        set_exception_handler($this->previousExceptionHandler);
    }

    public function testEveryOperationIsRecordedOnceAndMemoryStaysFlat(): void
    {
        $this->kernel = new SoakKernel($this->dir);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $controller = $this->controller($container, $this->connection($container), $this->twig($container));

        $memory = [];
        $metricTotals = [];
        for ($i = 1; $i <= self::WARMUP + self::MEASURED; ++$i) {
            $request = Request::create('/items/'.$i);
            $request->attributes->set('_controller', $controller);
            $request->attributes->set('_route', 'items_show');
            $response = $this->kernel->handle($request);
            $this->kernel->terminate($request, $response);
            $container->get('services_resetter')->reset();

            self::assertNull(Context::storage()->scope(), "request $i left a context scope open");

            $spans = $this->exporter->getSpans();
            $this->exporter->getStorage()->exchangeArray([]);
            $names = array_map(static fn ($span): string => $span->getName(), $spans);
            sort($names);
            $expected = self::EXPECTED_SPANS;
            sort($expected);
            self::assertSame($expected, $names, "request $i span set");

            self::assertCount(1, $this->logExporter->getStorage(), "request $i log records");
            $this->logExporter->getStorage()->exchangeArray([]);

            if ($i > self::WARMUP) {
                $this->metricReader->collect();
                foreach ($this->metricExporter->collect(true) as $metric) {
                    foreach ($metric->data->dataPoints as $point) {
                        $metricTotals[$metric->name] = ($metricTotals[$metric->name] ?? 0)
                            + ($metric->data instanceof Histogram ? $point->count : ($metric->data instanceof Sum ? $point->value : 0));
                    }
                }
            } else {
                $this->metricReader->collect();
                $this->metricExporter->collect(true);
            }

            if (self::WARMUP === $i || self::WARMUP + self::MEASURED === $i) {
                gc_collect_cycles();
                $memory[] = memory_get_usage();
            }
        }

        $perRequest = static fn (string $name): float => ($metricTotals[$name] ?? 0) / self::MEASURED;
        self::assertSame(1.0, $perRequest('http.server.request.duration'));
        self::assertSame(4.0, $perRequest('http.client.request.duration'), 'Symfony direct, PSR-18 over Symfony, plain PSR-18, Guzzle');
        self::assertSame(2.0, $perRequest('db.client.operation.duration'));
        self::assertSame(1.0, $perRequest('messaging.client.sent.messages'));
        self::assertSame(1.0, $perRequest('messaging.client.consumed.messages'));

        $growth = ($memory[1] - $memory[0]) / self::MEASURED;
        self::assertLessThanOrEqual(self::MAX_GROWTH_BYTES_PER_REQUEST, $growth, \sprintf('memory grew %.1f bytes per request after warm-up', $growth));
    }

    private function controller(ContainerInterface $container, Connection $connection, \Twig\Environment $twig): \Closure
    {
        $n = 0;

        return static function () use ($container, $connection, $twig, &$n): Response {
            ++$n;
            $container->get('http_client')->request('GET', 'https://api.example.com/symfony')->getContent();
            $container->get('psr18.http_client')->sendRequest(new Psr7Request('GET', 'https://api.example.com/psr18-over-symfony'))->getBody()->getContents();
            $container->get('app.psr18')->sendRequest(new Psr7Request('GET', 'https://api.example.com/plain-psr18'));
            $container->get('app.guzzle')->get('https://api.example.com/guzzle');
            $container->get('cache.app')->get('key'.($n % 10), static fn (): string => 'value');
            $connection->executeQuery('SELECT v FROM items WHERE id = ?', [1])->fetchAllAssociative();
            $connection->executeStatement('UPDATE items SET v = ? WHERE id = 1', ['v'.$n]);
            $container->get('messenger.default_bus')->dispatch(new SoakMessage($n));
            $container->get('logger')->error('soak', ['n' => $n]);
            $twig->render('page', ['name' => 'x']);

            return new Response('ok');
        };
    }

    private function connection(ContainerInterface $container): Connection
    {
        $guzzle = $container->get('app.guzzle');
        $config = (new \ReflectionProperty(\GuzzleHttp\Client::class, 'config'))->getValue($guzzle);
        self::assertIsArray($config);
        $config['handler']->setHandler(static fn () => Create::promiseFor(new Psr7Response(200, ['Content-Length' => '2'], 'ok')));

        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares([$container->get(TraceableMiddleware::class), $container->get(MeteredMiddleware::class)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $dbal);
        $connection->executeStatement('CREATE TABLE items (id INTEGER PRIMARY KEY, v TEXT)');
        $connection->executeStatement("INSERT INTO items (id, v) VALUES (1, 'a')");
        $this->exporter->getStorage()->exchangeArray([]);
        $this->metricReader->collect();
        $this->metricExporter->collect(true);

        return $connection;
    }

    private function twig(ContainerInterface $container): \Twig\Environment
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['page' => 'Hello {{ name }} {% include "part" %}', 'part' => 'part']));
        $twig->addExtension($container->get(OpenTelemetryTwigExtension::class));

        return $twig;
    }
}
