<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection;

use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\RealtimeNotifier;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension as BaseExtension;
use Symfony\Component\Messenger\MessageBusInterface;

class Extension extends BaseExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('subscription.yml');

        $hub = $config['subscriptions']['mercure'];

        // set hubUrl
        $container->findDefinition(\sprintf('%s.subscription_publisher', $this->getAlias()))
            ->replaceArgument(0, $hub['url']);
        // jwt publish and subscribe providers
        $jwtPublishProviderID = \sprintf('%s.subscription_jwt_publish_provider', $this->getAlias());
        if (JwtPublishProvider::class === $hub['jwt']['publish_provider']) {
            $container->register($jwtPublishProviderID, JwtPublishProvider::class)
                ->addArgument($hub['jwt']['publish_secret_key']);
        } else {
            $container->setAlias($jwtPublishProviderID, $hub['jwt']['publish_provider']);
        }
        $jwtSubscribeProviderID = \sprintf('%s.subscription_jwt_subscribe_provider', $this->getAlias());
        if (JwtSubscribeProvider::class === $hub['jwt']['subscribe_provider']) {
            $container->register($jwtSubscribeProviderID, JwtSubscribeProvider::class)
                ->addArgument($hub['jwt']['subscribe_secret_key']);
        } else {
            $container->setAlias($jwtSubscribeProviderID, $hub['jwt']['subscribe_provider']);
        }

        // storage
        $storageID = $config['subscriptions']['storage']['handler_id'];
        if (FilesystemSubscribeStorage::class === $storageID) {
            $container->register(SubscribeStorageInterface::class, $storageID)
                ->setArguments([$config['subscriptions']['storage']['path']]);
        } else {
            $container->setAlias(SubscribeStorageInterface::class, $storageID);
        }

        // realtime notifier topic url pattern and bus
        $bus = $config['subscriptions']['bus'] ?? null;
        $attributes = null === $bus ? [] : ['bus' => $bus];

        $realtimeNotifierDefinition = $container->findDefinition(RealtimeNotifier::class)
            ->replaceArgument(3, $config['subscriptions']['topic_url_pattern'])
            ->addTag('messenger.message_handler', $attributes);
        if (\class_exists('Symfony\\Component\\Messenger\\MessageBusInterface')) {
            $realtimeNotifierDefinition
                ->addMethodCall('setBus', [new Reference($bus ?? MessageBusInterface::class)]);
        }
    }

    public function getAlias()
    {
        return Configuration::NAME;
    }

    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($container->getParameter('kernel.project_dir').'/var');
    }
}
