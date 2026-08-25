<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\MessengerMiddlewarePass;
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

    public function testBuildRegistersMessengerMiddlewarePass(): void
    {
        $container = new ContainerBuilder();
        $bundle = new OpenTelemetryBundle();
        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $found = false;
        foreach ($passes as $pass) {
            if ($pass instanceof MessengerMiddlewarePass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'MessengerMiddlewarePass should be registered');
    }

    public function testBootDoesNotSetOpenTelemetryConfigWithoutConfiguration(): void
    {
        $container = new ContainerBuilder();
        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        foreach ([Variables::OTEL_EXPORTER_OTLP_HEADERS, Variables::OTEL_RESOURCE_ATTRIBUTES, Variables::OTEL_PHP_AUTOLOAD_ENABLED] as $variable) {
            self::assertArrayNotHasKey($variable, $_SERVER);
            self::assertArrayNotHasKey($variable, $_ENV);
            self::assertFalse(getenv($variable));
            self::assertFalse(Configuration::has($variable));
        }

        $reflection = new \ReflectionClass(Globals::class);

        self::assertIsArray($reflection->getStaticPropertyValue('initializers'));
        self::assertSame([], $reflection->getStaticPropertyValue('initializers'));
    }

    public function testOpenTelemetryWasNotAutoloadedIfNotConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => false,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => [],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $reflection = new \ReflectionClass(Globals::class);
        self::assertSame([], $reflection->getStaticPropertyValue('initializers'));
    }

    public function testOpenTelemetryWasAutoloadedIfConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => [],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $reflection = new \ReflectionClass(Globals::class);

        self::assertIsArray($reflection->getStaticPropertyValue('initializers'));
        self::assertNotSame([], $reflection->getStaticPropertyValue('initializers'));
    }

    public function testOpenTelemetryWasNotAutoloadedIfAutoloadWasAlreadyEnabledUsingServerGlobalBeforeBundleBoot(): void
    {
        $_SERVER[Variables::OTEL_PHP_AUTOLOAD_ENABLED] = 'true';

        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => [],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $reflection = new \ReflectionClass(Globals::class);
        self::assertSame([], $reflection->getStaticPropertyValue('initializers'));
    }

    public function testOpenTelemetryWasNotAutoloadedIfAutoloadWasAlreadyEnabledUsingPutenvBeforeBundleBoot(): void
    {
        putenv(\sprintf('%1$s=%2$s', Variables::OTEL_PHP_AUTOLOAD_ENABLED, 'true'));

        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => [],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $reflection = new \ReflectionClass(Globals::class);
        self::assertSame([], $reflection->getStaticPropertyValue('initializers'));
    }

    public function testDebugScopesDisabledWhenAssertionsAreOnOutsideDebug(): void
    {
        if ('1' !== \ini_get('zend.assertions')) {
            self::markTestSkipped('zend.assertions is not enabled in this runtime.');
        }

        unset($_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'], $_ENV['OTEL_PHP_DEBUG_SCOPES_DISABLED']);

        $this->bootWithDebug(false);

        self::assertSame('true', $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED']);
    }

    public function testDebugScopesLeftAloneInDebugMode(): void
    {
        if ('1' !== \ini_get('zend.assertions')) {
            self::markTestSkipped('zend.assertions is not enabled in this runtime.');
        }

        unset($_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'], $_ENV['OTEL_PHP_DEBUG_SCOPES_DISABLED']);

        $this->bootWithDebug(true);

        self::assertArrayNotHasKey('OTEL_PHP_DEBUG_SCOPES_DISABLED', $_SERVER);
    }

    public function testExplicitDebugScopesValueIsNotOverwritten(): void
    {
        $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'] = 'false';
        unset($_ENV['OTEL_PHP_DEBUG_SCOPES_DISABLED']);

        $this->bootWithDebug(false);

        self::assertSame('false', $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED']);

        unset($_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED']);
    }

    public function testDebugScopesDisabledWithoutSdkConfig(): void
    {
        if ('1' !== \ini_get('zend.assertions')) {
            self::markTestSkipped('zend.assertions is not enabled in this runtime.');
        }

        unset($_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'], $_ENV['OTEL_PHP_DEBUG_SCOPES_DISABLED']);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        self::assertSame(
            'true',
            $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'],
            'sdk.enabled defaults to false, so this must not depend on the sdk config parameter',
        );
    }

    private function bootWithDebug(bool $debug): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => false,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => [],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();
    }

    public function testOpenTelemetryConfigurationWasUsedWithPutEnv(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => true,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $expectedResourceAttributes = 'service.version=1.0,deployment.environment=dev';

        self::assertSame($expectedResourceAttributes, $_SERVER[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, $_ENV[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, getenv(Variables::OTEL_RESOURCE_ATTRIBUTES));

        self::assertSame(['service.version' => '1.0', 'deployment.environment' => 'dev'], Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES));

        $expectedExporterOtlpHeaders = 'other-config-value=abc,Authorization=api-key';

        self::assertSame($expectedExporterOtlpHeaders, $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, $_ENV[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, getenv(Variables::OTEL_EXPORTER_OTLP_HEADERS));

        self::assertSame(['other-config-value' => 'abc', 'Authorization' => 'api-key'], Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS));
    }

    public function testOpenTelemetryConfigurationWasUsedWithoutPutEnv(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => false,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $expectedResourceAttributes = 'service.version=1.0,deployment.environment=dev';

        self::assertSame($expectedResourceAttributes, $_SERVER[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, $_ENV[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertFalse(getenv(Variables::OTEL_RESOURCE_ATTRIBUTES));

        self::assertSame(['service.version' => '1.0', 'deployment.environment' => 'dev'], Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES));

        $expectedExporterOtlpHeaders = 'other-config-value=abc,Authorization=api-key';

        self::assertSame($expectedExporterOtlpHeaders, $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, $_ENV[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertFalse(getenv(Variables::OTEL_EXPORTER_OTLP_HEADERS));

        self::assertSame(['other-config-value' => 'abc', 'Authorization' => 'api-key'], Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS));
    }

    public function testOpenTelemetryConfigurationWasMergedWithoutPutEnv(): void
    {
        $_SERVER[Variables::OTEL_RESOURCE_ATTRIBUTES] = 'service.version=2.0,custom.name=custom.value';
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS] = 'other-config-value=foo,custom.header=custom.abc';

        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => false,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $expectedResourceAttributes = 'service.version=1.0,custom.name=custom.value,deployment.environment=dev';

        self::assertSame($expectedResourceAttributes, $_SERVER[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, $_ENV[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertFalse(getenv(Variables::OTEL_RESOURCE_ATTRIBUTES));

        self::assertSame(['service.version' => '1.0', 'custom.name' => 'custom.value', 'deployment.environment' => 'dev'], Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES));

        $expectedExporterOtlpHeaders = 'other-config-value=abc,custom.header=custom.abc,Authorization=api-key';

        self::assertSame($expectedExporterOtlpHeaders, $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, $_ENV[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertFalse(getenv(Variables::OTEL_EXPORTER_OTLP_HEADERS));

        self::assertSame(['other-config-value' => 'abc', 'custom.header' => 'custom.abc', 'Authorization' => 'api-key'], Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS));
    }

    public function testOpenTelemetryConfigurationWasMergedWithPutEnv(): void
    {
        putenv(\sprintf('%1$s=%2$s', Variables::OTEL_RESOURCE_ATTRIBUTES, 'service.version=2.0,custom.name=custom.value'));
        putenv(\sprintf('%1$s=%2$s', Variables::OTEL_EXPORTER_OTLP_HEADERS, 'other-config-value=foo,custom.header=custom.abc'));

        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => true,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        $expectedResourceAttributes = 'service.version=1.0,custom.name=custom.value,deployment.environment=dev';

        self::assertSame($expectedResourceAttributes, $_SERVER[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, $_ENV[Variables::OTEL_RESOURCE_ATTRIBUTES]);
        self::assertSame($expectedResourceAttributes, getenv(Variables::OTEL_RESOURCE_ATTRIBUTES));

        self::assertSame(['service.version' => '1.0', 'custom.name' => 'custom.value', 'deployment.environment' => 'dev'], Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES));

        $expectedExporterOtlpHeaders = 'other-config-value=abc,custom.header=custom.abc,Authorization=api-key';

        self::assertSame($expectedExporterOtlpHeaders, $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, $_ENV[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame($expectedExporterOtlpHeaders, getenv(Variables::OTEL_EXPORTER_OTLP_HEADERS));

        self::assertSame(['other-config-value' => 'abc', 'custom.header' => 'custom.abc', 'Authorization' => 'api-key'], Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS));
    }

    public function testMergeEnvVariablePercentEncodesReservedCharacters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.sdk.config', [
            'enabled' => true,
            'autoload_enabled' => false,
            'use_putenv' => false,
            'resource_attributes' => [],
            'exporter_otlp_headers' => ['Authorization' => 'Basic a,b=c'],
        ]);

        $bundle = new OpenTelemetryBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        // "," and "=" in the value must not corrupt the baggage-format variable.
        self::assertSame('Authorization=Basic%20a%2Cb%3Dc', $_SERVER[Variables::OTEL_EXPORTER_OTLP_HEADERS]);
        self::assertSame(
            'Basic a,b=c',
            rawurldecode(Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS)['Authorization']),
            'the OTLP exporter rawurldecodes header values, so the round-trip must be lossless',
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Globals::reset();

        foreach ([Variables::OTEL_EXPORTER_OTLP_HEADERS, Variables::OTEL_RESOURCE_ATTRIBUTES, Variables::OTEL_PHP_AUTOLOAD_ENABLED] as $variable) {
            unset($_SERVER[$variable]);
            unset($_ENV[$variable]);
            putenv($variable);
        }
    }
}
