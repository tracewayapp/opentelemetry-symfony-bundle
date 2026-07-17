<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\E2E;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Traceway\OpenTelemetryBundle\TracingInterface;

final class HelloController
{
    public function __construct(
        private readonly TracingInterface $tracing,
        private readonly CacheInterface $cache,
    ) {
    }

    public function __invoke(string $name): JsonResponse
    {
        $greeting = $this->tracing->trace('e2e.work', fn (): string => strtoupper($name));

        $cached = $this->cache->get('e2e.greeting.'.$name, static fn (): string => 'hello '.$name);

        return new JsonResponse(['greeting' => $greeting, 'cached' => $cached]);
    }
}
