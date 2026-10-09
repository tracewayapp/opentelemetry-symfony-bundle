<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Soak;

final class SoakMessage
{
    public function __construct(public readonly int $n)
    {
    }
}
