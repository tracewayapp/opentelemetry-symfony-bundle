<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\HttpClient;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * Injects trace context headers into an array of HTTP headers.
 *
 * Symfony HttpClient accepts headers as ['Header-Name' => 'value'] or
 * ['Header-Name: value']. This setter uses the key-value style.
 *
 */
final class HeadersPropagationSetter implements PropagationSetterInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<string, string|list<string>> $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        $carrier[$key] = $value;
    }
}
