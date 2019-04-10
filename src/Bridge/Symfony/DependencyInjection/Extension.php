<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection;

use Overblog\GraphQLSubscription\Bridge\Symfony\Action\EndpointAction;
use Overblog\GraphQLSubscription\Bridge\Symfony\ExecutorAdapter\GraphQLBundleExecutorAdapter;
use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Overblog\GraphQLSubscription\SubscriptionManager;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension as BaseExtension;
use Symfony\Component\Mercure\Publisher;

class Extension extends BaseExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('subscription.yml');

        $this->setMercureHubDefinition($config, $container);
        $this->setJwtProvidersDefinitions($config, $container);
        $this->setStorageDefinition($config, $container);
        $this->setSubscriptionManagerDefinition($config, $container);
        $this->setSubscriptionActionRequestParser($config, $container);
    }

    private function setSubscriptionActionRequestParser(array $config, ContainerBuilder $container): void
    {
        $container->register(EndpointAction::class)
            ->setArguments([$this->resolveCallableServiceReference($config['request_parser'])])
            ->addTag('controller.service_arguments')
        ;
    }

    private function setSubscriptionManagerDefinition(array $config, ContainerBuilder $container): void
    {
        $bus = $config['bus'] ?? null;
        $attributes = null === $bus ? [] : ['bus' => $bus];

        $container->register(SubscriptionManager::class)
            ->setArguments([
                new Reference($this->getAlias().'.publisher'),
                new Reference(SubscribeStorageInterface::class),
                $this->resolveCallableServiceReference($config['graphql_executor']),
                $config['topic_url_pattern'],
                new Reference($this->getAlias().'.jwt_subscribe_provider'),
                new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                $this->resolveCallableServiceReference($config['schema_builder']),
            ])
            ->addMethodCall(
                'setBus',
                [new Reference($bus ?? 'message_bus', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
            )
            ->addTag('messenger.message_handler', $attributes);

        if (GraphQLBundleExecutorAdapter::class === $config['graphql_executor']['id']) {
            $container->register(GraphQLBundleExecutorAdapter::class)
                ->setArguments([new Reference('Overblog\\GraphQLBundle\\Request\\Executor')]);
        }
    }

    private function setStorageDefinition(array $config, ContainerBuilder $container): void
    {
        $storageID = $config['storage']['handler_id'];
        if (FilesystemSubscribeStorage::class === $storageID) {
            $container->register(SubscribeStorageInterface::class, $storageID)
                ->setArguments([$config['storage']['path']]);
        } else {
            $container->setAlias(SubscribeStorageInterface::class, $storageID);
        }
    }

    private function setJwtProvidersDefinitions(array $config, ContainerBuilder $container): void
    {
        foreach (['publish' => JwtPublishProvider::class, 'subscribe' => JwtSubscribeProvider::class] as $type => $default) {
            $options = $config['mercure_hub'][$type];
            // jwt publish and subscribe providers
            $jwtProviderID = \sprintf('%s.jwt_%s_provider', $this->getAlias(), $type);
            if ($default === $options['provider']) {
                if (!isset($options['secret_key'])) {
                    throw new InvalidConfigurationException(\sprintf(
                        '"mercure_hub.%s.secret_key" is required when using with default provider %s.',
                        $type,
                        $default
                    ));
                }

                $container->register($jwtProviderID, $default)
                    ->addArgument($options['secret_key']);
            } else {
                $container->setAlias($jwtProviderID, $options['provider']);
            }
        }
    }

    private function setMercureHubDefinition(array $config, ContainerBuilder $container): void
    {
        $serviceId = \sprintf('%s.publisher', $this->getAlias());

        if (null !== $config['mercure_hub']['handler_id']) {
            $container->setAlias($serviceId, $config['mercure_hub']['handler_id']);
        } else {
            $container->register($serviceId, Publisher::class)
                ->setArguments([
                    $config['mercure_hub']['url'],
                    new Reference('overblog_graphql_subscription.jwt_publish_provider'),
                    $this->resolveCallableServiceReference($config['mercure_hub']['http_client']),
                ]);
        }
    }

    private function resolveCallableServiceReference(array $callableServiceParams)
    {
        $callableServiceRef = null;
        if (isset($callableServiceParams['id'])) {
            $callableServiceRef = new Reference($callableServiceParams['id']);
            if (null !== $callableServiceParams['method']) {
                $callableServiceRef = [$callableServiceRef, $callableServiceParams['method']];
            }
        }

        return $callableServiceRef;
    }

    public function getAlias()
    {
        return Configuration::NAME;
    }

    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($container->getParameter('kernel.project_dir').'/var');
    }

    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('mercure')) {
            $container->prependExtensionConfig(
                Configuration::NAME,
                [
                    'mercure_hub' => [
                        'publisher' => ['handler_id' => 'mercure.hub.default.publisher'],
                    ],
                ]
            );
        }

        if ($container->hasExtension('overblog_graphql')) {
            $container->prependExtensionConfig(
                Configuration::NAME,
                [
                    'graphql_executor' => GraphQLBundleExecutorAdapter::class,
                ]
            );
        } elseif ($container->hasExtension('api_platform')) {
            $container->prependExtensionConfig(
                Configuration::NAME,
                [
                    'graphql_executor' => 'api_platform.graphql.executor::executeQuery',
                    'schema_builder' => 'api_platform.graphql.schema_builder::getSchema',
                ]
            );
        }
    }
}
