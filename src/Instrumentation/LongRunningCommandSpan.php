<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Instrumentation;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;

/** Marks the root span of a long-lived worker command so only_with_parent does not count it as a parent. */
final class LongRunningCommandSpan
{
    /** @var ContextKeyInterface<SpanInterface>|null Context lookups compare key identity, so the instance must be reused. */
    private static ?ContextKeyInterface $key = null;

    /** @return ContextKeyInterface<SpanInterface> */
    public static function key(): ContextKeyInterface
    {
        return self::$key ??= Context::createKey(self::class);
    }

    public static function storeIn(ContextInterface $context, SpanInterface $span): ContextInterface
    {
        return $context->with(self::key(), $span);
    }

    /** True when the active span IS the marked worker root, not a descendant of it. */
    public static function isCurrent(ContextInterface $context): bool
    {
        $marked = $context->get(self::key());

        if (!$marked instanceof SpanInterface) {
            return false;
        }

        return $marked->getContext()->getSpanId() === Span::fromContext($context)->getContext()->getSpanId();
    }
}
