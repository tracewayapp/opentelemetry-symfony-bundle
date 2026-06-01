<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class CheckContext
{
    public function __construct(
        public readonly ParameterBagInterface $params,
        public readonly EnvReaderInterface $env,
        public readonly GlobalsAccessorInterface $globals,
        public readonly bool $skipNetwork,
        public readonly float $networkTimeoutSeconds = 1.0,
    ) {}

    public function param(string $key, mixed $default = null): mixed
    {
        if (!$this->params->has($key)) {
            return $default;
        }

        return $this->params->get($key);
    }
}
