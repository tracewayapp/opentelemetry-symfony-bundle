<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Twig;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

/**
 * Creates INTERNAL spans for Twig template rendering.
 *
 * Uses Twig's built-in {@see ProfilerNodeVisitor} to inject enter/leave
 * hooks around template bodies. Only templates (not blocks or macros)
 * produce spans, keeping the trace volume reasonable.
 *
 * Span matching uses spl_object_id() on the Profile instance, which is
 * guaranteed unique per enter/leave pair and avoids stack-based mismatch
 * edge cases.
 *
 * Implements ResetInterface to safely drain any orphaned spans between
 * requests in long-running processes (Swoole, RoadRunner).
 */
final class OpenTelemetryTwigExtension extends AbstractExtension implements ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    /** @var array<int, array{SpanInterface, ScopeInterface}> */
    private array $spans = [];

    /** @var string[] */
    private readonly array $excludedTemplates;

    /**
     * @param string[] $excludedTemplates Template name prefixes to skip (e.g. @WebProfiler/, @Debug/)
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedTemplates = [],
    ) {
        $this->excludedTemplates = array_values($excludedTemplates);
    }

    public function __destruct()
    {
        foreach (array_reverse($this->spans, true) as [$span, $scope]) {
            $span->end();
            $scope->detach();
        }

        $this->spans = [];
    }

    /**
     * @return list<ProfilerNodeVisitor>
     */
    public function getNodeVisitors(): array
    {
        return [new ProfilerNodeVisitor(static::class)];
    }

    public function reset(): void
    {
        foreach (array_reverse($this->spans, true) as [$span, $scope]) {
            $span->end();
            @$scope->detach();
        }

        $this->spans = [];
        $this->tracer = null;
        $this->enabled = null;
    }

    public function enter(Profile $profile): void
    {
        if (!$this->isEnabled() || !$profile->isTemplate() || $this->isExcluded($profile->getTemplate())) {
            return;
        }

        $span = $this->getTracer()
            ->spanBuilder('twig.render ' . $profile->getTemplate())
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('twig.template', $profile->getTemplate())
            ->startSpan();

        $scope = $span->activate();
        $this->spans[spl_object_id($profile)] = [$span, $scope];
    }

    public function leave(Profile $profile): void
    {
        $id = spl_object_id($profile);

        if (!isset($this->spans[$id])) {
            return;
        }

        [$span, $scope] = $this->spans[$id];
        unset($this->spans[$id]);

        $span->end();
        $scope->detach();
    }

    private function isExcluded(string $templateName): bool
    {
        foreach ($this->excludedTemplates as $prefix) {
            if (str_starts_with($templateName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }
}
