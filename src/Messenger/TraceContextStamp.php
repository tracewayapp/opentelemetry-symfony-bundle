<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries W3C Trace Context headers across Messenger transport boundaries.
 *
 * Attached on dispatch so that async workers can link back to (or continue)
 * the originating trace.
 */
final class TraceContextStamp implements StampInterface
{
    /**
     * @param array<string, string> $headers Propagation headers (e.g. traceparent, tracestate)
     */
    public function __construct(
        private readonly array $headers,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
