<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\E2E;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware;
use Traceway\OpenTelemetryBundle\TracingInterface;

final class HelloController
{
    public const MAX_DB_SPANS = 5;
    public const DB_STATEMENTS = 22;
    public const GUZZLE_URL = 'http://localhost:4318/e2e-guzzle-probe';

    public function __construct(
        private readonly TracingInterface $tracing,
        private readonly CacheInterface $cache,
        private readonly ClientInterface $guzzle,
        private readonly TraceableMiddleware $dbTracing,
    ) {
    }

    public function __invoke(string $name): JsonResponse
    {
        $greeting = $this->tracing->trace('e2e.work', fn (): string => strtoupper($name));

        $cached = $this->cache->get('e2e.greeting.'.$name, static fn (): string => 'hello '.$name);

        // A real request to the collector on a host the OTLP auto-exclusion does not cover; it answers 404.
        $guzzleStatus = $this->guzzle->request('GET', self::GUZZLE_URL, ['http_errors' => false])->getStatusCode();

        $config = new Configuration();
        $config->setMiddlewares([$this->dbTracing]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $connection->executeStatement('CREATE TABLE items (id INTEGER PRIMARY KEY, v TEXT)');
        $connection->executeStatement("INSERT INTO items (id, v) VALUES (1, 'a')");
        for ($i = 3; $i <= self::DB_STATEMENTS; ++$i) {
            $connection->executeStatement('UPDATE items SET v = ? WHERE id = 1', ['v'.$i]);
        }

        return new JsonResponse(['greeting' => $greeting, 'cached' => $cached, 'guzzle' => $guzzleStatus]);
    }
}
