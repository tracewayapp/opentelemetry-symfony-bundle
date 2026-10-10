<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientMetricsPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;

final class HttpClientMetricsPassTest extends TestCase
{
    public function testDecoratesDefaultHttpClient(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', true);
        $container->setParameter('open_telemetry.metrics_meter_name', 'test-meter');
        $container->setParameter('open_telemetry.http_client_metrics_excluded_hosts', ['cdn.example.com']);
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        (new HttpClientMetricsPass())->process($container);

        self::assertTrue($container->hasDefinition('http_client.otel_metrics'));

        $decorator = $container->getDefinition('http_client.otel_metrics');
        self::assertSame(MeteredHttpClient::class, $decorator->getClass());
        self::assertSame('test-meter', $decorator->getArgument('$meterName'));
        self::assertSame(['cdn.example.com'], $decorator->getArgument('$excludedHosts'));
        self::assertSame(['http_client', 'http_client.otel_metrics.inner', HttpClientMetricsPass::METER_PRIORITY], $decorator->getDecoratedService());
    }

    public function testMeterSitsInsideTheRetryWrapperAndOutsideTheTracer(): void
    {
        self::assertGreaterThan(25, HttpClientMetricsPass::METER_PRIORITY, 'inside RetryableHttpClient on Symfony 7 and 8');
        self::assertLessThan(HttpClientTracingPass::TRACER_PRIORITY, HttpClientMetricsPass::METER_PRIORITY, 'outside the tracer');
    }

    public function testFallsBackToEmptyExcludedHostsWhenParameterMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', true);
        $container->setParameter('open_telemetry.metrics_meter_name', 'test-meter');
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        (new HttpClientMetricsPass())->process($container);

        $decorator = $container->getDefinition('http_client.otel_metrics');
        self::assertSame([], $decorator->getArgument('$excludedHosts'));
    }

    public function testDecoratesTaggedScopedClients(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', true);
        $container->setParameter('open_telemetry.metrics_meter_name', 'test-meter');

        $scopedDef = new Definition(HttpClientInterface::class);
        $scopedDef->addTag('http_client.client');
        $container->setDefinition('my_api.client', $scopedDef);

        (new HttpClientMetricsPass())->process($container);

        self::assertTrue($container->hasDefinition('my_api.client.otel_metrics'));
    }

    public function testSkipsWhenDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', false);
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        (new HttpClientMetricsPass())->process($container);

        self::assertFalse($container->hasDefinition('http_client.otel_metrics'));
    }

    public function testSkipsWhenParameterMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        (new HttpClientMetricsPass())->process($container);

        self::assertFalse($container->hasDefinition('http_client.otel_metrics'));
    }
}
