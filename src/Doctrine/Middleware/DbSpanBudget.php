<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use OpenTelemetry\API\Trace\Span;

/** @internal */
final class DbSpanBudget
{
    public const DROPPED_ATTRIBUTE = 'traceway.db.spans_dropped';

    private ?string $traceId = null;
    private int $recorded = 0;
    private int $dropped = 0;

    public function __construct(
        private readonly int $maxSpansPerTrace,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->maxSpansPerTrace > 0;
    }

    public function tryAcquire(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $parent = Span::getCurrent();
        $parentContext = $parent->getContext();

        if (!$parentContext->isValid()) {
            return true;
        }

        $traceId = $parentContext->getTraceId();
        if ($traceId !== $this->traceId) {
            $this->traceId = $traceId;
            $this->recorded = 0;
            $this->dropped = 0;
        }

        if ($this->recorded < $this->maxSpansPerTrace) {
            ++$this->recorded;

            return true;
        }

        ++$this->dropped;
        $parent->setAttribute(self::DROPPED_ATTRIBUTE, $this->dropped);

        return false;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
