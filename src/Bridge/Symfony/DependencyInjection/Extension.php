<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection;

use Overblog\GraphQLBundle\Request\Executor;
use Overblog\GraphQLSubscription\Bridge\Symfony\Action\EndpointAction;
use Overblog\GraphQLSubscription\Bridge\Symfony\EventListener\SpoolNotificationsHandler;
use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Overblog\GraphQLSubscription\SubscriptionManager;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension as BaseExtension;
use Symfony\Component\Messenger\MessageBusInterface;

class Extension extends BaseExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('subscription.yml');

        $this->setMercureHubDefinitionArgs($config, $container);
        $this->setJwtProvidersDefinitions($config, $container);
        $this->setStorageDefinitions($config, $container);
        $this->setRealtimeNotifierDefinitionArgs($config, $container);
        $this->setSubscriptionActionRequestParser($config, $container);
    }

    private function setSpoolNotificationHandlerDefinition(array $config, ContainerBuilder $container): void
    {
        $container->register(SpoolNotificationsHandler::class)
            ->setArguments([new Reference(SubscriptionManager::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate']);
    }

    private function setSubscriptionActionRequestParser(array $config, ContainerBuilder $container): void
    {
        if (!empty($config['request_parser']['id'])) {
            $parser = new Reference($config['request_parser']['id']);
            if (null !== $config['request_parser']['method']) {
                $parser = [$parser, $config['request_parser']['method']];
            }

            $container->findDefinition(EndpointAction::class)
                ->replaceArgument(1, $parser);
        }
    }

    private function setRealtimeNotifierDefinitionArgs(array $config, ContainerBuilder $container): void
    {
        $bus = $config['bus'] ?? null;
        $attributes = null === $bus ? [] : ['bus' => $bus];
        $executor = new Reference($config['graphql_executor']['id']);
        if (null !== $config['graphql_executor']['method']) {
            $executor = [$executor, $config['graphql_executor']['method']];
        }

        $realtimeNotifierDefinition = $container->findDefinition(SubscriptionManager::class)
            ->replaceArgument(2, $executor)
            ->replaceArgument(3, $config['topic_url_pattern'])
            ->addTag('messenger.message_handler', $attributes);
        if (\class_exists('Symfony\\Component\\Messenger\\MessageBusInterface')) {
            $realtimeNotifierDefinition
                ->addMethodCall('setBus', [new Reference($bus ?? MessageBusInterface::class)]);
        } else {
            $this->setSpoolNotificationHandlerDefinition($config, $container);
        }
    }

    private function setStorageDefinitions(array $config, ContainerBuilder $container): void
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
                $container->register($jwtProviderID, $default)
                    ->addArgument($options['secret_key']);
            } else {
                $container->setAlias($jwtProviderID, $options['provider']);
            }
        }
    }

    private function setMercureHubDefinitionArgs(array $config, ContainerBuilder $container): void
    {
        $container->findDefinition(\sprintf('%s.publisher', $this->getAlias()))
            ->replaceArgument(0, $config['mercure_hub']['url']);
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
        if ($container->hasExtension('overblog_graphql')) {
            $container->prependExtensionConfig(
                Configuration::NAME,
                [
                    'graphql_executor' => [
                        'id' => Executor::class,
                        'method' => 'execute',
                    ],
                ]
            );
        }
    }
}
