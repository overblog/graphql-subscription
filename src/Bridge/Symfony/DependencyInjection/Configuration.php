<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection;

use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const NAME = 'overblog_graphql_subscription';

    private $varDir;

    public function __construct(string $varDir)
    {
        $this->varDir = $varDir;
    }

    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder(self::NAME);
        $rootNode = $this->getRootNodeWithoutDeprecation($treeBuilder, self::NAME);

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->mercureHubNode('mercure_hub'))
                ->scalarNode('topic_url_pattern')
                    ->isRequired()
                    ->info('the url pattern to build topic, it should contain the "{id}" replacement string, optional placeholders "{channel}" and "{schemaName}" can also be used.')
                    ->example('https://example.com/subscriptions/{id} or https://{schemaName}.example.com/{channel}/{id}.json')
                ->end()
                ->scalarNode('bus')
                    ->info('Name of the Messenger bus where the handler for this hub must be registered. Default to the default bus if Messenger is enabled.')
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('handler_id')
                            ->defaultValue(FilesystemSubscribeStorage::class)
                            ->info('The service id to handler subscription persistence.')
                        ->end()
                        ->scalarNode('path')
                            ->defaultValue($this->varDir.'/graphql-subscriptions')
                            ->info('The path where to stock files is useful only id using default filesystem subscription storage.')
                        ->end()
                    ->end()
                ->end()
                ->append($this->callableServiceNode('graphql_executor'))
                ->append($this->callableServiceNode('schema_builder', false))
                ->append($this->callableServiceNode('request_parser', false))
            ->end()
        ->end();

        return $treeBuilder;
    }

    private function mercureHubNode(string $name): ArrayNodeDefinition
    {
        $builder = new TreeBuilder($name);
        $node = $this->getRootNodeWithoutDeprecation($builder, $name);
        $node
            ->isRequired()
            ->addDefaultsIfNotSet()
            ->normalizeKeys(false)
            ->children()
                ->scalarNode('handler_id')
                    ->defaultNull()
                    ->info('Mercure handler service id.')
                    ->example('mercure.hub.default.publisher')
                ->end()
                ->scalarNode('url')
                    ->info('URL of mercure hub endpoint.')
                    ->example('https://private.example.com/hub')
                ->end()
                ->scalarNode('public_url')
                    ->info('Public URL of mercure hub endpoint.')
                    ->example('https://public.example.com/hub')
                ->end()
                ->scalarNode('http_client')
                    ->defaultNull()
                    ->info('The ID of the http client service.')
                ->end()
                ->arrayNode('publish')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('provider')
                            ->defaultValue(JwtPublishProvider::class)
                            ->info('The ID of a service to call to generate the publisher JSON Web Token.')
                        ->end()
                        ->scalarNode('secret_key')->info('The JWT secret key to use to publish to this hub.')->end()
                    ->end()
                ->end()
                ->arrayNode('subscribe')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('provider')
                            ->defaultValue(JwtSubscribeProvider::class)
                            ->info('The ID of a service to call to generate the subscriber JSON Web Token.')
                        ->end()
                        ->scalarNode('secret_key')->info('The JWT secret key to use for subscribe.')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $node;
    }

    private function callableServiceNode(string $name, bool $isRequired = true): ArrayNodeDefinition
    {
        $builder = new TreeBuilder($name);
        $node = $this->getRootNodeWithoutDeprecation($builder, $name);
        if ($isRequired) {
            $node->isRequired();
        }
        $node
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifString()
                ->then(function (string $callableString): array {
                    $callable = \explode('::', $callableString, 2);

                    return ['id' => $callable[0], 'method' => $callable[1] ?? null];
                })
            ->end()
            ->children()
                ->scalarNode('id')->isRequired()->end()
                ->scalarNode('method')->defaultNull()->end()
            ->end()
        ->end();

        return $node;
    }

    private function getRootNodeWithoutDeprecation(TreeBuilder $builder, string $name): ArrayNodeDefinition
    {
        // BC layer for symfony/config 4.1 and older
        return \method_exists($builder, 'getRootNode') ? $builder->getRootNode() : $builder->root($name);
    }
}
