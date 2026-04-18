<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Twig;

use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;
use Twig\Profiler\Profile;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryTwigExtensionTest extends TestCase
{
    use OTelTestTrait;

    private OpenTelemetryTwigExtension $extension;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->extension = new OpenTelemetryTwigExtension();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testTemplateRenderCreatesSpan(): void
    {
        $profile = new Profile('home.html.twig', Profile::TEMPLATE, 'home.html.twig');

        $this->extension->enter($profile);
        $this->extension->leave($profile);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('twig.render home.html.twig', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('home.html.twig', $attrs['twig.template']);
    }

    public function testBlockDoesNotCreateSpan(): void
    {
        $profile = new Profile('base.html.twig', Profile::BLOCK, 'content');

        $this->extension->enter($profile);
        $this->extension->leave($profile);

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testMacroDoesNotCreateSpan(): void
    {
        $profile = new Profile('macros.html.twig', Profile::MACRO, 'render_button');

        $this->extension->enter($profile);
        $this->extension->leave($profile);

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testNestedTemplatesCreateParentChildSpans(): void
    {
        $outerProfile = new Profile('layout.html.twig', Profile::TEMPLATE, 'layout.html.twig');
        $innerProfile = new Profile('header.html.twig', Profile::TEMPLATE, 'header.html.twig');

        $this->extension->enter($outerProfile);
        $this->extension->enter($innerProfile);
        $this->extension->leave($innerProfile);
        $this->extension->leave($outerProfile);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        self::assertSame('twig.render header.html.twig', $spans[0]->getName());
        self::assertSame('twig.render layout.html.twig', $spans[1]->getName());

        $outerSpanId = $spans[1]->getSpanId();
        $innerParentSpanId = $spans[0]->getParentSpanId();
        self::assertSame($outerSpanId, $innerParentSpanId);
    }

    public function testBlocksBetweenTemplatesIgnored(): void
    {
        $templateProfile = new Profile('page.html.twig', Profile::TEMPLATE, 'page.html.twig');
        $blockProfile = new Profile('page.html.twig', Profile::BLOCK, 'sidebar');

        $this->extension->enter($templateProfile);
        $this->extension->enter($blockProfile);
        $this->extension->leave($blockProfile);
        $this->extension->leave($templateProfile);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('twig.render page.html.twig', $spans[0]->getName());
    }

    public function testLeaveWithoutEnterIsNoop(): void
    {
        $profile = new Profile('orphan.html.twig', Profile::TEMPLATE, 'orphan.html.twig');

        $this->extension->leave($profile);

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testCustomTracerName(): void
    {
        $extension = new OpenTelemetryTwigExtension('my-app');
        $profile = new Profile('test.html.twig', Profile::TEMPLATE, 'test.html.twig');

        $extension->enter($profile);
        $extension->leave($profile);

        $spans = $this->exporter->getSpans();
        self::assertSame('my-app', $spans[0]->getInstrumentationScope()->getName());
    }

    public function testGetNodeVisitorsReturnsProfilerNodeVisitor(): void
    {
        $visitors = $this->extension->getNodeVisitors();

        self::assertCount(1, $visitors);
        self::assertInstanceOf(\Twig\Profiler\NodeVisitor\ProfilerNodeVisitor::class, $visitors[0]);
    }

    public function testDestructorDrainsSpansWhenLeaveNeverCalled(): void
    {
        $extension = new OpenTelemetryTwigExtension();

        $outer = new Profile('layout.html.twig', Profile::TEMPLATE, 'layout.html.twig');
        $inner = new Profile('header.html.twig', Profile::TEMPLATE, 'header.html.twig');

        $extension->enter($outer);
        $extension->enter($inner);

        unset($extension);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
    }

    public function testExcludedTemplatesAreSkipped(): void
    {
        $extension = new OpenTelemetryTwigExtension('opentelemetry-symfony', ['@WebProfiler/', '@Debug/']);

        $excluded = new Profile('@WebProfiler/profiler/layout.html.twig', Profile::TEMPLATE, '@WebProfiler/profiler/layout.html.twig');
        $included = new Profile('home.html.twig', Profile::TEMPLATE, 'home.html.twig');

        $extension->enter($excluded);
        $extension->leave($excluded);

        $extension->enter($included);
        $extension->leave($included);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('twig.render home.html.twig', $spans[0]->getName());
    }

    public function testExcludedTemplatesPrefixMatching(): void
    {
        $extension = new OpenTelemetryTwigExtension('opentelemetry-symfony', ['@Debug/']);

        $debugTemplate = new Profile('@Debug/exception.html.twig', Profile::TEMPLATE, '@Debug/exception.html.twig');
        $regularTemplate = new Profile('debug_page.html.twig', Profile::TEMPLATE, 'debug_page.html.twig');

        $extension->enter($debugTemplate);
        $extension->leave($debugTemplate);

        $extension->enter($regularTemplate);
        $extension->leave($regularTemplate);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('twig.render debug_page.html.twig', $spans[0]->getName());
    }

    public function testResetDrainsOrphanedSpans(): void
    {
        $profile = new Profile('page.html.twig', Profile::TEMPLATE, 'page.html.twig');

        $this->extension->enter($profile);
        $this->extension->reset();

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('twig.render page.html.twig', $spans[0]->getName());
    }

    public function testResetAllowsNewSpansAfterwards(): void
    {
        $first = new Profile('old.html.twig', Profile::TEMPLATE, 'old.html.twig');
        $this->extension->enter($first);
        $this->extension->reset();

        $second = new Profile('new.html.twig', Profile::TEMPLATE, 'new.html.twig');
        $this->extension->enter($second);
        $this->extension->leave($second);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('twig.render old.html.twig', $spans[0]->getName());
        self::assertSame('twig.render new.html.twig', $spans[1]->getName());
    }

    public function testSplObjectIdMatchingHandlesDuplicateTemplateNames(): void
    {
        $first = new Profile('partial.html.twig', Profile::TEMPLATE, 'partial.html.twig');
        $second = new Profile('partial.html.twig', Profile::TEMPLATE, 'partial.html.twig');

        $this->extension->enter($first);
        $this->extension->enter($second);
        $this->extension->leave($second);
        $this->extension->leave($first);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('twig.render partial.html.twig', $spans[0]->getName());
        self::assertSame('twig.render partial.html.twig', $spans[1]->getName());
    }
}
