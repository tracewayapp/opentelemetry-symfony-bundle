<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

final class OpenTelemetryBundleTest extends TestCase
{
    public function testGetPathReturnsPackageRoot(): void
    {
        $bundle = new OpenTelemetryBundle();

        $expected = \dirname(__DIR__);
        self::assertSame($expected, $bundle->getPath());
    }

    public function testBuildRegistersHttpClientTracingPass(): void
    {
        $container = new ContainerBuilder();
        $bundle = new OpenTelemetryBundle();
        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $found = false;
        foreach ($passes as $pass) {
            if ($pass instanceof HttpClientTracingPass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'HttpClientTracingPass should be registered');
    }

    public function testBuildRegistersCacheTracingPass(): void
    {
        $container = new ContainerBuilder();
        $bundle = new OpenTelemetryBundle();
        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $found = false;
        foreach ($passes as $pass) {
            if ($pass instanceof CacheTracingPass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'CacheTracingPass should be registered');
    }

    public function testVersionConstant(): void
    {
        self::assertSame('1.4.0', OpenTelemetryBundle::VERSION);
    }
}
