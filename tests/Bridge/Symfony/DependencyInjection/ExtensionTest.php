<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests\Bridge\Symfony\DependencyInjection;

use Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection\Extension;
use Overblog\GraphQLSubscription\Bridge\Symfony\EventListener\SpoolNotificationsHandler;
use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Overblog\GraphQLSubscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ExtensionTest extends TestCase
{
    public function testMinimalConfig(): void
    {
        $container = $this->load();
        $this->assertDefinitionExists($container, 'overblog_graphql_subscription.jwt_publish_provider', JwtPublishProvider::class);
        $this->assertDefinitionExists($container, 'overblog_graphql_subscription.jwt_subscribe_provider', JwtSubscribeProvider::class);
        $this->assertDefinitionExists($container, 'overblog_graphql_subscription.publisher');
        $this->assertDefinitionExists($container, SubscribeStorageInterface::class, FilesystemSubscribeStorage::class);
        $this->assertDefinitionExists($container, SubscriptionManager::class);
        $this->assertDefinitionExists($container, SpoolNotificationsHandler::class);
        $this->assertSame(['messenger.message_handler' => [[]]], $container->getDefinition(SubscriptionManager::class)->getTags());
    }

    public function testBus(): void
    {
        $config = $this->getMinimalConfiguration();
        $config[] = ['bus' => 'my_bus'];
        $container = $this->load($config);
        $this->assertSame(['messenger.message_handler' => [['bus' => 'my_bus']]], $container->getDefinition(SubscriptionManager::class)->getTags());
    }

    private function load(?array $config = null)
    {
        $config = $config ?? self::getMinimalConfiguration();
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        (new Extension())->load($config, $container);

        return $container;
    }

    private function assertDefinitionExists(ContainerBuilder $container, string $serviceID, ?string $expectedClass = null): void
    {
        $this->assertTrue($container->hasDefinition($serviceID));
        if ($expectedClass) {
            $this->assertSame(
                $container->getDefinition($serviceID)->getClass(),
                $expectedClass
            );
        }
    }

    private function getMinimalConfiguration(): array
    {
        return [
            [
                'mercure_hub' => [
                    'url' => 'http://example.com/hub',
                    'publish' => ['secret_key' => 'mySecretPubKey'],
                    'subscribe' => ['secret_key' => 'mySecretSubKey'],
                ],
                'topic_url_pattern' => 'https://example.com/subscriptions/{id}',
                'graphql_executor' => ['id' => 'graphql_executor_handler'],
            ],
        ];
    }
}
