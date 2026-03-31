<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('open_telemetry');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('traces_enabled')
                    ->info('Enable automatic HTTP trace instrumentation.')
                    ->defaultTrue()
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
                                if (!\is_string($p)) {
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
                    ->info('HTTP status codes >= this value are marked as errors when no exception was thrown.')
                    ->defaultValue(500)
                    ->min(400)
                    ->max(599)
                ->end()
                ->booleanNode('console_enabled')
                    ->info('Instrument Symfony Console: auto-create spans for console commands.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('console_excluded_commands')
                    ->info('Console command names to exclude from tracing (e.g. cache:clear, assets:install).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('http_client_enabled')
                    ->info('Instrument Symfony HttpClient: auto-create CLIENT spans for outgoing HTTP requests with context propagation.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('messenger_enabled')
                    ->info('Instrument Symfony Messenger: auto-create spans for dispatched and consumed messages.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('messenger_root_spans')
                    ->info('Create root spans for consumed messages instead of linking to the dispatching trace. Useful for task-oriented backends (e.g. Traceway, Sentry).')
                    ->defaultFalse()
                ->end()
                ->booleanNode('doctrine_enabled')
                    ->info('Instrument Doctrine DBAL: auto-create CLIENT spans for database queries.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('doctrine_record_statements')
                    ->info('Record SQL on spans. Prepared statements use ? placeholders; query()/exec() record raw SQL which may contain literal values. Disable in production if raw SQL may contain sensitive data.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('cache_enabled')
                    ->info('Instrument Symfony Cache: auto-create INTERNAL spans for cache get/delete operations.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('cache_excluded_pools')
                    ->info('Cache pool service IDs to exclude from tracing (e.g. cache.system, cache.validator).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('twig_enabled')
                    ->info('Instrument Twig: auto-create INTERNAL spans for template rendering.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('twig_excluded_templates')
                    ->info('Template name prefixes to exclude from tracing (e.g. @WebProfiler/, @Debug/).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('monolog_enabled')
                    ->info('Inject trace_id and span_id into Monolog log records for log-trace correlation.')
                    ->defaultTrue()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
