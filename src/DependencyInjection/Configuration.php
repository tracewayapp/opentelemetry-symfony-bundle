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
                        ->then(static fn (array $paths): array => array_map(
                            static fn (string $p): string => str_starts_with($p, '/') ? $p : '/' . $p,
                            $paths,
                        ))
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
            ->end()
        ;

        return $treeBuilder;
    }
}
