<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/** Extracts the short (unqualified) name from a fully qualified class name. */
final class ClassName
{
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        $name = false !== $pos ? substr($fqcn, $pos + 1) : $fqcn;

        return '' !== $name ? $name : $fqcn;
    }
}
