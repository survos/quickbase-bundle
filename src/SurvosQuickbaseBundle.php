<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Quickbase\Adapter\QuickbaseAdapterFactory;
use Survos\Quickbase\Client\QuickbaseClient;
use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\Schema\QuickbaseSchemaManager;
use Survos\RecordStore\SurvosRecordStoreBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosRecordStoreBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosQuickbaseBundle extends AbstractSurvosBundle
{
    private const DEFAULT_BASE_URI = 'https://api.quickbase.com/v1/';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('realm')->isRequired()->cannotBeEmpty()
                ->info('Quickbase realm hostname, for example example.quickbase.com.')
            ->end()
            ->scalarNode('token')->isRequired()->cannotBeEmpty()
                ->info('Permanent Quickbase user token. Prefer an env-backed secret.')
            ->end()
            ->arrayNode('apps')
                ->info('Named Quickbase applications, keyed by local alias.')
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                        ->booleanNode('readonly')
                            ->defaultFalse()
                            ->info('Mark this application as inspection-only for consumers such as SchemaSteward.')
                        ->end()
                        ->arrayNode('tables')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                                    ->arrayNode('fields')
                                        ->useAttributeAsKey('name')
                                        ->integerPrototype()->min(1)->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->defaultValue([])
            ->end()
            ->scalarNode('base_uri')->defaultValue(self::DEFAULT_BASE_URI)->end()
            ->scalarNode('user_agent')->defaultValue('survos/quickbase-bundle')->end()
            ->floatNode('timeout')->defaultValue(30.0)->min(0.1)->end()
            ->integerNode('max_retries')->defaultValue(3)->min(0)->end()
        ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(QuickbaseClient::class)
            ->arg('$http', service('quickbase.client'))
            ->public();
        $services->alias(QuickbaseClientInterface::class, QuickbaseClient::class)->public();
        $services->set(QuickbaseSchemaManager::class)->public();
        $services->set(QuickbaseAppRegistry::class)
            ->arg('$apps', $config['apps'])
            ->arg('$realm', $config['realm'])
            ->public();
        $services->set(QuickbaseAdapterFactory::class)
            ->tag('survos_record_store.adapter_factory');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        $config = self::rawConfig($builder);
        $builder->prependExtensionConfig('framework', [
            'http_client' => [
                'scoped_clients' => [
                    'quickbase.client' => [
                        'base_uri' => self::stringConfig($config, 'base_uri', self::DEFAULT_BASE_URI),
                        'timeout' => self::numericConfig($config, 'timeout', 30.0),
                        'headers' => [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json; charset=UTF-8',
                            'QB-Realm-Hostname' => self::stringConfig($config, 'realm', '%env(QUICKBASE_REALM)%'),
                            'Authorization' => sprintf('QB-USER-TOKEN %s', self::stringConfig($config, 'token', '%env(QUICKBASE_API_KEY)%')),
                            'User-Agent' => self::stringConfig($config, 'user_agent', 'survos/quickbase-bundle'),
                        ],
                        'retry_failed' => [
                            'enabled' => true,
                            'max_retries' => self::integerConfig($config, 'max_retries', 3),
                            'http_codes' => [429, 500, 502, 503, 504],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function rawConfig(ContainerBuilder $builder): array
    {
        $merged = [];
        foreach ($builder->getExtensionConfig('survos_quickbase') as $config) {
            $merged = array_merge($merged, $config);
        }

        return $merged;
    }

    /** @param array<string, mixed> $config */
    private static function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $config */
    private static function numericConfig(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /** @param array<string, mixed> $config */
    private static function integerConfig(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }
}
