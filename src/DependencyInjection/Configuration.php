<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class Configuration implements ConfigurationInterface
{
    /**
     * Map of legacy v1.x flat keys to their v2.0 nested location.
     * Tuple: [signal-group, subsystem-or-null, leaf-key].
     *
     * @var array<string, array{string, string|null, string}>
     */
    private const LEGACY_MAP = [
        'traces_enabled'                     => ['traces', null,          'enabled'],
        'tracer_name'                        => ['traces', null,          'tracer_name'],
        'excluded_paths'                     => ['traces', null,          'excluded_paths'],
        'record_client_ip'                   => ['traces', null,          'record_client_ip'],
        'error_status_threshold'             => ['traces', null,          'error_status_threshold'],
        'console_enabled'                    => ['traces', 'console',     'enabled'],
        'console_excluded_commands'          => ['traces', 'console',     'excluded_commands'],
        'http_client_enabled'                => ['traces', 'http_client', 'enabled'],
        'http_client_excluded_hosts'         => ['traces', 'http_client', 'excluded_hosts'],
        'messenger_enabled'                  => ['traces', 'messenger',   'enabled'],
        'messenger_root_spans'               => ['traces', 'messenger',   'root_spans'],
        'doctrine_enabled'                   => ['traces', 'doctrine',    'enabled'],
        'doctrine_record_statements'         => ['traces', 'doctrine',    'record_statements'],
        'cache_enabled'                      => ['traces', 'cache',       'enabled'],
        'cache_excluded_pools'               => ['traces', 'cache',       'excluded_pools'],
        'twig_enabled'                       => ['traces', 'twig',        'enabled'],
        'twig_excluded_templates'            => ['traces', 'twig',        'excluded_templates'],
        'scheduler_enabled'                  => ['traces', 'scheduler',   'enabled'],
        'mailer_enabled'                     => ['traces', 'mailer',      'enabled'],
        'mailer_record_subject'              => ['traces', 'mailer',      'record_subject'],
        'monolog_enabled'                    => ['logs',   'correlation', 'enabled'],
        'log_export_enabled'                 => ['logs',   'export',      'enabled'],
        'log_export_level'                   => ['logs',   'export',      'level'],
        'log_export_capture_code_attributes' => ['logs',   'export',      'capture_code_attributes'],
        'log_export_unprefixed_attributes'   => ['logs',   'export',      'unprefixed_attributes'],
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('open_telemetry');

        $treeBuilder->getRootNode()
            ->beforeNormalization()
                ->ifArray()
                ->then(static function (array $config): array {
                    /** @var array<array-key, mixed> $config */
                    return self::migrateLegacyKeys($config);
                })
            ->end()
            ->children()
                ->append($this->buildTracesNode())
                ->append($this->buildMetricsNode())
                ->append($this->buildLogsNode())
                ->append($this->buildSdkNode())
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * @param array<array-key, mixed> $config
     * @return array<array-key, mixed>
     */
    private static function migrateLegacyKeys(array $config): array
    {
        foreach (self::LEGACY_MAP as $oldKey => [$group, $subsystem, $newKey]) {
            if (!\array_key_exists($oldKey, $config)) {
                continue;
            }

            $newPath = null === $subsystem
                ? sprintf('%s.%s', $group, $newKey)
                : sprintf('%s.%s.%s', $group, $subsystem, $newKey);

            $nested = $config[$group] ?? null;
            $hasNested = false;
            if (\is_array($nested)) {
                if (null === $subsystem) {
                    $hasNested = \array_key_exists($newKey, $nested);
                } else {
                    $sub = $nested[$subsystem] ?? null;
                    $hasNested = \is_array($sub) && \array_key_exists($newKey, $sub);
                }
            }

            if ($hasNested) {
                throw new InvalidConfigurationException(sprintf(
                    'Cannot set both legacy "open_telemetry.%s" and nested "open_telemetry.%s" in the same configuration block. Use the nested form only.',
                    $oldKey,
                    $newPath,
                ));
            }

            trigger_deprecation(
                'traceway/opentelemetry-symfony',
                '2.0',
                'Configuring "open_telemetry.%s" is deprecated, use "open_telemetry.%s" instead. The legacy key will be removed in v4.0.',
                $oldKey,
                $newPath,
            );

            $value = $config[$oldKey];
            if (!isset($config[$group]) || !\is_array($config[$group])) {
                $config[$group] = [];
            }
            if (null === $subsystem) {
                $config[$group][$newKey] = $value;
            } else {
                if (!isset($config[$group][$subsystem]) || !\is_array($config[$group][$subsystem])) {
                    $config[$group][$subsystem] = [];
                }
                $config[$group][$subsystem][$newKey] = $value;
            }
            unset($config[$oldKey]);
        }

        return $config;
    }

    private function buildTracesNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('traces');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->info('OpenTelemetry tracing configuration.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Enable HTTP request tracing via OpenTelemetrySubscriber. Each non-HTTP subsystem (console, http_client, messenger, doctrine, cache, twig, scheduler, mailer) has its own enabled toggle below.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('propagator')
                    ->info('Context propagation format. "w3c" uses traceparent/tracestate headers. "xray" uses X-Amzn-Trace-Id (requires open-telemetry/contrib-aws). "w3c+xray" injects and extracts both.')
                    ->defaultValue('w3c')
                    ->validate()
                        ->ifNotInArray(['w3c', 'xray', 'w3c+xray'])
                        ->thenInvalid('Invalid propagator "%s". Valid values: w3c, xray, w3c+xray.')
                    ->end()
                ->end()
                ->scalarNode('id_generator')
                    ->info('Trace ID generation format. "default" uses random hex. "xray" uses epoch-prefixed X-Ray format (requires open-telemetry/contrib-aws). When "xray", the bundle builds and registers a TracerProvider using OTEL_* env vars plus the X-Ray ID generator.')
                    ->defaultValue('default')
                    ->validate()
                        ->ifNotInArray(['default', 'xray'])
                        ->thenInvalid('Invalid id_generator "%s". Valid values: default, xray.')
                    ->end()
                ->end()
                ->scalarNode('tracer_name')
                    ->info('Instrumentation library name reported to the OTel backend.')
                    ->defaultValue('opentelemetry-symfony')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('excluded_paths')
                    ->info('URL path prefixes to exclude from tracing (e.g. /health, /_profiler).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $paths): array {
                            $normalized = [];
                            foreach ($paths as $p) {
                                if (!\is_string($p) || '' === trim($p)) {
                                    continue;
                                }
                                $normalized[] = str_starts_with($p, '/') ? $p : '/' . $p;
                            }
                            return $normalized;
                        })
                    ->end()
                ->end()
                ->booleanNode('record_client_ip')
                    ->info('Record the client IP address on spans. Disable for GDPR compliance.')
                    ->defaultTrue()
                ->end()
                ->integerNode('error_status_threshold')
                    ->info('HTTP status codes >= this value are marked as errors when no exception was thrown. Minimum 500: OTel forbids Error status for 4xx on server spans.')
                    ->defaultValue(500)
                    ->min(500)
                    ->max(599)
                ->end()
                ->arrayNode('console')
                    ->info('Instrument Symfony Console: auto-create spans for console commands.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('excluded_commands')
                            ->info('Console command names to exclude from tracing (e.g. cache:clear, assets:install). Long-lived commands like messenger:consume are excluded by default because per-message tracing is handled by the Messenger middleware.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['messenger:consume', 'messenger:consume-messages'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http_client')
                    ->info('Instrument Symfony HttpClient: auto-create CLIENT spans for outgoing HTTP requests with context propagation.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('excluded_hosts')
                            ->info('Hostnames to exclude from outgoing HTTP client tracing (e.g. your OTLP collector). The OTLP endpoint is auto-excluded.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->info('Instrument Symfony Messenger: auto-create spans for dispatched and consumed messages.')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('root_spans')
                            ->info('Create root spans for consumed messages instead of linking to the dispatching trace. Useful for task-oriented backends (e.g. Traceway, Sentry).')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Instrument Doctrine DBAL: auto-create CLIENT spans for database queries. Supports doctrine/dbal ^3.6 and ^4.0.')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('record_statements')
                            ->info('Record SQL as db.query.text on spans. Off by default per DB semconv (raw query()/exec() SQL may contain unsanitized literals). Enable to capture SQL; prepared statements use ? placeholders, query()/exec() record raw SQL.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->info('Instrument Symfony Cache: auto-create INTERNAL spans for cache get/delete operations.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('excluded_pools')
                            ->info('Cache pool service IDs to exclude from tracing (e.g. cache.system, cache.validator).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('twig')
                    ->info('Instrument Twig: auto-create INTERNAL spans for template rendering.')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('excluded_templates')
                            ->info('Template name prefixes to exclude from tracing (e.g. @WebProfiler/, @Debug/).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scheduler')
                    ->info('Instrument Symfony Scheduler: emit a CONSUMER span per scheduled-task run with trigger metadata. Requires symfony/scheduler. When enabled, the Messenger middleware auto-skips envelopes carrying Symfony\'s ScheduledStamp so the richer scheduler span owns the work unit without duplicate producer/consumer spans.')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('mailer')
                    ->info('Instrument Symfony Mailer: PRODUCER span around MailerInterface::send and CLIENT span around the transport send.')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('record_subject')
                            ->info('Record the email subject as the "email.subject" attribute on the PRODUCER span. Off by default because subjects can contain personal data.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function buildMetricsNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('metrics');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->info('OpenTelemetry metrics configuration. Off by default. The master switch registers the MeterRegistry service for manual instrumentation; each subsystem below has its own toggle.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch. Registers the MeterRegistry service. Must be true for any subsystem metric to be emitted.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('meter_name')
                    ->info('Instrumentation library name reported with emitted metrics. Shared across all built-in metric subscribers.')
                    ->defaultValue('opentelemetry-symfony')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('messenger')
                    ->info('Emit messaging.process.duration (histogram) and messaging.client.consumed.messages (counter) on the consume path. Requires metrics.enabled and symfony/messenger.')
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('excluded_queues')
                            ->info('Receiver names to skip when emitting messenger metrics.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Emit db.client.operation.duration (histogram) for every DBAL query, exec, prepared statement execution and transaction control. Requires metrics.enabled and doctrine/dbal.')
                    ->canBeEnabled()
                ->end()
                ->arrayNode('http_server')
                    ->info('Emit http.server.request.duration (histogram), http.server.active_requests (up/down counter), http.server.request.body.size and http.server.response.body.size (histograms). Requires metrics.enabled.')
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('excluded_paths')
                            ->info('URL path prefixes to skip when emitting HTTP server metrics (e.g. /health, /_profiler).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->beforeNormalization()
                                ->ifArray()
                                ->then(static function (array $paths): array {
                                    $normalized = [];
                                    foreach ($paths as $p) {
                                        if (!\is_string($p) || '' === trim($p)) {
                                            continue;
                                        }
                                        $normalized[] = str_starts_with($p, '/') ? $p : '/' . $p;
                                    }
                                    return $normalized;
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http_client')
                    ->info('Emit http.client.request.duration (histogram), http.client.request.body.size and http.client.response.body.size (histograms) on outgoing requests. Requires metrics.enabled and symfony/http-client.')
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('excluded_hosts')
                            ->info('Hostnames to skip when emitting HTTP client metrics. The OTLP endpoint is auto-excluded.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('mailer')
                    ->info('Emit messaging.client.operation.duration (histogram) and messaging.client.sent.messages (counter) on outbound transport sends. Requires metrics.enabled and symfony/mailer.')
                    ->canBeEnabled()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function (array $c): bool {
                    $messenger = \is_array($c['messenger'] ?? null) ? $c['messenger'] : [];
                    return true === ($messenger['enabled'] ?? false) && true !== ($c['enabled'] ?? false);
                })
                ->thenInvalid('"open_telemetry.metrics.messenger.enabled" requires "open_telemetry.metrics.enabled" to be true.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $c): bool {
                    $doctrine = \is_array($c['doctrine'] ?? null) ? $c['doctrine'] : [];
                    return true === ($doctrine['enabled'] ?? false) && true !== ($c['enabled'] ?? false);
                })
                ->thenInvalid('"open_telemetry.metrics.doctrine.enabled" requires "open_telemetry.metrics.enabled" to be true.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $c): bool {
                    $httpServer = \is_array($c['http_server'] ?? null) ? $c['http_server'] : [];
                    return true === ($httpServer['enabled'] ?? false) && true !== ($c['enabled'] ?? false);
                })
                ->thenInvalid('"open_telemetry.metrics.http_server.enabled" requires "open_telemetry.metrics.enabled" to be true.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $c): bool {
                    $httpClient = \is_array($c['http_client'] ?? null) ? $c['http_client'] : [];
                    return true === ($httpClient['enabled'] ?? false) && true !== ($c['enabled'] ?? false);
                })
                ->thenInvalid('"open_telemetry.metrics.http_client.enabled" requires "open_telemetry.metrics.enabled" to be true.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $c): bool {
                    $mailer = \is_array($c['mailer'] ?? null) ? $c['mailer'] : [];
                    return true === ($mailer['enabled'] ?? false) && true !== ($c['enabled'] ?? false);
                })
                ->thenInvalid('"open_telemetry.metrics.mailer.enabled" requires "open_telemetry.metrics.enabled" to be true.')
            ->end()
        ;

        return $node;
    }

    private function buildLogsNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('logs');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->info('OpenTelemetry log configuration.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('correlation')
                    ->info('Monolog log-trace correlation: inject trace_id and span_id into log records.')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('export')
                    ->info('Native OpenTelemetry log export via the OTel Logs API. Requires a configured OTel LoggerProvider (e.g. via OTEL_LOGS_EXPORTER env var) and symfony/monolog-bundle.')
                    ->canBeEnabled()
                    ->children()
                        ->enumNode('level')
                            ->info('Minimum Monolog level to export via OTel.')
                            ->values(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])
                            ->defaultValue('debug')
                        ->end()
                        ->booleanNode('capture_code_attributes')
                            ->info('Resolve OTel code.file.path / code.line.number / code.function.name via debug_backtrace when Monolog\'s IntrospectionProcessor is not installed. Has a small per-log cost; prefer adding IntrospectionProcessor instead.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('unprefixed_attributes')
                            ->info('Emit Monolog context and extra fields as flat OTel attributes instead of under "monolog.context.*" / "monolog.extra.*". Matches the cross-ecosystem shape (Java/Python/.NET/JS all emit user fields flat).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function buildSdkNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('sdk');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->info('It can be used to configure OpenTelemetry SDK variables easier and adds support for Symfony Secrets. It sets environment variables during bundle boot method to ensure they are set on a compiled container.')
            ->addDefaultsIfNotSet()
            ->canBeEnabled()
            ->children()
                ->booleanNode('autoload_enabled')
                    ->info('If `true` OTEL_PHP_AUTOLOAD_ENABLED will be set automatically and load `_autoload.php`, if and only if Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED) returns false to avoid duplicate initialization. It defaults to `false`.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('use_putenv')
                    ->info('If `true` environment variables set by the SDK section will also use putenv(). Otherwise, only $_SERVER and $_ENV is used. Beware that putenv() is not thread safe, that\'s why it\'s not enabled by default.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('resource_attributes')
                    ->info('Merged/replaced key and value pairs with existing OTEL_RESOURCE_ATTRIBUTES variable. It defaults to an empty array.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('exporter_otlp_headers')
                    ->info('Merged/replaced key and value pairs with existing OTEL_EXPORTER_OTLP_HEADERS variable. It defaults to an empty array.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $node;
    }
}
