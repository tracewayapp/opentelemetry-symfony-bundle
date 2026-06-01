<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

interface EnvReaderInterface
{
    /** Empty string is treated as unset. */
    public function get(string $name): ?string;

    public function has(string $name): bool;
}
