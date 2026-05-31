<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

final class EnvReader implements EnvReaderInterface
{
    public function get(string $name): ?string
    {
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && '' !== $_SERVER[$name]) {
            return $_SERVER[$name];
        }

        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && '' !== $_ENV[$name]) {
            return $_ENV[$name];
        }

        $value = getenv($name);
        if (is_string($value) && '' !== $value) {
            return $value;
        }

        return null;
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }
}
