<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects trace context headers into a Symfony Response.
 *
 * Used by the response propagator to set headers like Server-Timing or
 * traceresponse on outgoing HTTP responses, enabling browser-side correlation.
 *
 */
final class ResponsePropagationSetter implements PropagationSetterInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param Response $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        $carrier->headers->set($key, $value);
    }
}
