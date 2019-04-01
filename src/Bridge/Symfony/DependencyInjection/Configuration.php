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
        $rootNode = self::getRootNodeWithoutDeprecation($treeBuilder, self::NAME);

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('mercure_hub')
                    ->isRequired()
                    ->addDefaultsIfNotSet()
                    ->normalizeKeys(false)
                    ->children()
                        ->scalarNode('url')
                            ->isRequired()
                            ->info('URL of mercure hub endpoint.')
                            ->example('https://demo.mercure.rocks/hub')
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
                ->end()
                ->scalarNode('topic_url_pattern')
                    ->isRequired()
                    ->info('the url pattern to build topic, it should contain the "{id}" replacement string, optional placeholders "{channel}" and "{schemaName}" can also be used.')
                    ->example('https://example.com/subscriptions/{id} or https://{schemaName}.example.com/{channel}/{id}.json')
                    ->validate()
                        ->ifTrue(function (?string $topicUrlPattern): bool {
                            return false === \filter_var($topicUrlPattern, FILTER_VALIDATE_URL) || false === \strpos($topicUrlPattern, '{id}');
                        })
                        ->thenInvalid('Topic url pattern should be a valid url and should contain the "{id}" replacement string but got %s.')
                    ->end()
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
                            ->defaultValue($this->varDir.'/overblog-graphql-subscriptions')
                            ->info('The path where to stock files is useful only id using default filesystem subscription storage.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('graphql_executor')
                    ->isRequired()
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function (string $id): array {
                            return ['id' => $id];
                        })
                    ->end()
                    ->children()
                        ->scalarNode('id')->isRequired()->end()
                        ->scalarNode('method')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('request_parser')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function (string $id): array {
                            return ['id' => $id];
                        })
                    ->end()
                    ->children()
                        ->scalarNode('id')->isRequired()->end()
                        ->scalarNode('method')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }

    /**
     * @internal
     *
     * @param TreeBuilder $builder
     * @param string|null $name
     * @param string      $type
     *
     * @return ArrayNodeDefinition|\Symfony\Component\Config\Definition\Builder\NodeDefinition
     */
    public static function getRootNodeWithoutDeprecation(TreeBuilder $builder, string $name, string $type = 'array')
    {
        // BC layer for symfony/config 4.1 and older
        return \method_exists($builder, 'getRootNode') ? $builder->getRootNode() : $builder->root($name, $type);
    }
}
