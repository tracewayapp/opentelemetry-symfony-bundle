<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Runtime;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime\ContribInstrumentationOverlapCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class ContribInstrumentationOverlapCheckTest extends TestCase
{
    private const ALL_ON = [
        'open_telemetry.traces.enabled' => true,
        'open_telemetry.http_client_enabled' => true,
        'open_telemetry.http_client.psr18_enabled' => true,
        'open_telemetry.http_client.guzzle_enabled' => true,
        'open_telemetry.traces.doctrine.enabled' => true,
        'open_telemetry.cache_enabled' => true,
        'open_telemetry.logs.correlation.enabled' => true,
        'open_telemetry.logs.export.enabled' => false,
    ];

    public function testOkWithoutTheExtension(): void
    {
        $result = $this->check(['open-telemetry/opentelemetry-auto-guzzle'], false)->run(CheckTestHelper::context(params: self::ALL_ON));

        self::assertSame(Status::Ok, $result->status);
        self::assertStringContainsString('not loaded', $result->message);
    }

    public function testOkWhenNoOverlappingPackageIsInstalled(): void
    {
        $result = $this->check([])->run(CheckTestHelper::context(params: self::ALL_ON));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testWarnsForEachInstalledOverlapAndBuildsTheDisableList(): void
    {
        $result = $this->check(['open-telemetry/opentelemetry-auto-guzzle', 'open-telemetry/opentelemetry-auto-pdo', 'open-telemetry/opentelemetry-auto-psr3'])
            ->run(CheckTestHelper::context(env: ['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'laravel'], params: self::ALL_ON));

        self::assertSame(Status::Warning, $result->status);
        self::assertStringContainsString('opentelemetry-auto-guzzle', $result->message);
        self::assertStringContainsString('opentelemetry-auto-pdo', $result->message);
        self::assertStringContainsString('opentelemetry-auto-psr3', $result->message);
        self::assertNotNull($result->remediation);
        self::assertStringContainsString('OTEL_PHP_DISABLED_INSTRUMENTATIONS=laravel,guzzle,pdo,psr3', $result->remediation);
    }

    public function testAlreadyDisabledInstrumentationIsNotReported(): void
    {
        $result = $this->check(['open-telemetry/opentelemetry-auto-guzzle', 'open-telemetry/opentelemetry-auto-pdo'])
            ->run(CheckTestHelper::context(env: ['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => ' Guzzle , pdo '], params: self::ALL_ON));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testDisabledAllSilencesTheCheck(): void
    {
        $result = $this->check(['open-telemetry/opentelemetry-auto-guzzle'])
            ->run(CheckTestHelper::context(env: ['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'all'], params: self::ALL_ON));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testNoOverlapWhenTheMatchingBundleFeatureIsOff(): void
    {
        $params = ['open_telemetry.http_client.guzzle_enabled' => false, 'open_telemetry.traces.doctrine.enabled' => false] + self::ALL_ON;

        $result = $this->check(['open-telemetry/opentelemetry-auto-guzzle', 'open-telemetry/opentelemetry-auto-pdo'])
            ->run(CheckTestHelper::context(params: $params));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testCurlOverlapsWhenEitherHttpPathIsTraced(): void
    {
        $params = ['open_telemetry.http_client_enabled' => false, 'open_telemetry.http_client.guzzle_enabled' => true] + self::ALL_ON;

        $result = $this->check(['open-telemetry/opentelemetry-auto-curl'])->run(CheckTestHelper::context(params: $params));

        self::assertSame(Status::Warning, $result->status);
        self::assertStringContainsString('=curl', (string) $result->remediation);
    }

    public function testEveryMappedPackageUsesTheContribNamingScheme(): void
    {
        foreach (ContribInstrumentationOverlapCheck::OVERLAPS as $package => [$instrumentation]) {
            self::assertStringStartsWith('open-telemetry/opentelemetry-auto-', $package);
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $instrumentation);
        }
    }

    /** @param list<string> $installed */
    private function check(array $installed, bool $extensionLoaded = true): ContribInstrumentationOverlapCheck
    {
        return new ContribInstrumentationOverlapCheck(
            static fn (string $package): bool => \in_array($package, $installed, true),
            static fn (): bool => $extensionLoaded,
        );
    }
}
