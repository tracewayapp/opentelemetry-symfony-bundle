<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Twig;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
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

    /** @var \SplObjectStorage<Profile, array{SpanInterface, ScopeInterface}> */
    private \SplObjectStorage $spans;

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
        $this->spans = new \SplObjectStorage();
    }

    public function __destruct()
    {
        $this->drainOrphans(suppressScopeNotice: false);
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
        $this->drainOrphans(suppressScopeNotice: true);
        $this->tracer = null;
        $this->enabled = null;
    }

    public function enter(Profile $profile): void
    {
        if (!$this->isEnabled() || !$profile->isTemplate() || $this->isExcluded($profile->getTemplate())) {
            return;
        }

        $span = $this->getTracer()
            ->spanBuilder('twig.render '.$profile->getTemplate())
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('twig.template', $profile->getTemplate())
            ->startSpan();

        $scope = $span->activate();
        $this->spans[$profile] = [$span, $scope];
    }

    public function leave(Profile $profile): void
    {
        if (!$this->spans->offsetExists($profile)) {
            return;
        }

        $this->closeNestedOrphans($profile);

        [$span, $scope] = $this->spans[$profile];
        $this->spans->offsetUnset($profile);

        $span->end();
        $scope->detach();
    }

    /**
     * Twig's profiler hooks are not exception-safe: a throwing template skips
     * its leave() call. Any span entered after the one now leaving is such an
     * orphan — close them in reverse order so scope detaching stays nested,
     * and mark them failed since their render never completed.
     */
    private function closeNestedOrphans(Profile $current): void
    {
        $orphans = [];
        $seen = false;

        foreach ($this->spans as $profile) {
            if ($seen) {
                $orphans[] = $profile;
            } elseif ($profile === $current) {
                $seen = true;
            }
        }

        foreach (array_reverse($orphans) as $profile) {
            [$span, $scope] = $this->spans[$profile];
            $this->spans->offsetUnset($profile);

            $span->setStatus(StatusCode::STATUS_ERROR, 'Template rendering did not complete');
            $span->end();
            $scope->detach();
        }
    }

    private function drainOrphans(bool $suppressScopeNotice): void
    {
        $profiles = [];
        foreach ($this->spans as $profile) {
            $profiles[] = $profile;
        }

        foreach (array_reverse($profiles) as $profile) {
            [$span, $scope] = $this->spans[$profile];
            $span->end();
            if ($suppressScopeNotice) {
                @$scope->detach();
            } else {
                $scope->detach();
            }
        }

        $this->spans = new \SplObjectStorage();
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
        return $this->tracer ??= Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }
}
