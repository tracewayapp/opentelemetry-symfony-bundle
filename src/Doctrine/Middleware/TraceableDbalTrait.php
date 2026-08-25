<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Traceway\OpenTelemetryBundle\Instrumentation\LongRunningCommandSpan;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Lazy tracer init and span-wrapped execution shared by the traced DBAL
 * connection/statement middlewares (DBAL 3 and DBAL 4 variants).
 */
trait TraceableDbalTrait
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $op
     *
     * @return T
     */
    private function traced(string $sql, \Closure $op): mixed
    {
        if (!$this->isEnabled()) {
            return $op();
        }

        // only_with_parent: skip orphan root spans (e.g. messenger transport polling)
        if ($this->onlyWithParent && !$this->hasMeaningfulParent()) {
            return $op();
        }

        $span = DbSpanBuilder::startSpan(
            $this->getTracer(),
            $sql,
            $this->recordStatements,
            $this->dbSystem,
            $this->dbName,
            $this->serverAddress,
            $this->serverPort,
        );

        try {
            return $op();
        } catch (\Throwable $e) {
            DbSpanBuilder::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /** A worker command's process-lifetime span is active but is not a unit of work: queries directly under it are the poll loop. */
    private function hasMeaningfulParent(): bool
    {
        $context = Context::getCurrent();

        return Span::fromContext($context)->getContext()->isValid()
            && !LongRunningCommandSpan::isCurrent($context);
    }
}
