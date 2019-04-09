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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

class ExtensionTest extends TestCase
{
    private const PROJECT_DIR = '/path/to/project';

    public function testMinimalConfig(): void
    {
        $container = $this->load();
        $this->assertDefinition($container, 'overblog_graphql_subscription.jwt_publish_provider', JwtPublishProvider::class);
        $this->assertDefinition($container, 'overblog_graphql_subscription.jwt_subscribe_provider', JwtSubscribeProvider::class);
        $this->assertDefinition($container, 'overblog_graphql_subscription.publisher');
        $this->assertDefinition($container, SubscribeStorageInterface::class, FilesystemSubscribeStorage::class);
        $this->assertSame(self::PROJECT_DIR.'/var/graphql-subscriptions', $container->getDefinition(SubscribeStorageInterface::class)->getArgument(0));
        $this->assertDefinition($container, SubscriptionManager::class, null, ['messenger.message_handler' => [[]]]);
        $this->assertDefinition(
            $container,
            SpoolNotificationsHandler::class,
            null,
            [
                'kernel.event_listener' => [
                    [
                        'event' => 'kernel.terminate',
                        'method' => 'onKernelTerminate',
                    ],
                ],
            ]
        );
    }

    public function testGraphQLExecutor(): void
    {
        $config = $this->getMinimalConfiguration();
        $config[] = ['graphql_executor' => 'graphql_executor_handler::execute'];
        $container = $this->load($config);
        $this->assertDefinition($container, SubscriptionManager::class);
        $executor = $container->getDefinition(SubscriptionManager::class)->getArgument(2);
        $this->assertInstanceOf(Reference::class, $executor[0]);
        $this->assertSame('graphql_executor_handler', (string) $executor[0]);
        $this->assertSame('execute', $executor[1]);
    }

    public function testCustomPublisher(): void
    {
        $config = $this->getMinimalConfiguration();
        $config[] = ['mercure_hub' => ['handler_id' => 'custom_publisher']];
        $container = $this->load($config);
        $this->assertTrue($container->hasAlias('overblog_graphql_subscription.publisher'));
        $this->assertSame('custom_publisher', (string) $container->getAlias('overblog_graphql_subscription.publisher'));
    }

    public function testBus(): void
    {
        $config = $this->getMinimalConfiguration();
        $config[] = ['bus' => 'my_bus'];
        $container = $this->load($config);
        $this->assertSame(['messenger.message_handler' => [['bus' => 'my_bus']]], $container->getDefinition(SubscriptionManager::class)->getTags());
    }

    /**
     * @param string $type
     * @param string $class
     *
     * @dataProvider getProviderDataProvider
     */
    public function testThrowExceptionIfSecretKeyIsNotSetWithDefaultProvider(string $type, string $class): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(\sprintf(
            '"mercure_hub.%s.secret_key" is required when using with default provider %s.',
            $type,
            $class
        ));
        $config = $this->getMinimalConfiguration();
        unset($config[0]['mercure_hub'][$type]['secret_key']);
        $this->load($config);
    }

    /**
     * @param string $type
     * @param string $class
     *
     * @dataProvider getProviderDataProvider
     */
    public function testCustomProvider(string $type, string $class): void
    {
        $config = $this->getMinimalConfiguration();
        $config[0]['mercure_hub'][$type]['provider'] = 'custom_provider_'.$type;

        $container = $this->load($config);
        $this->assertDefinitionNotExists($container, $class);
    }

    public function testCustomStorage(): void
    {
        $config = $this->getMinimalConfiguration();
        $config[0]['storage']['handler_id'] = 'CustomStorage';

        $container = $this->load($config);
        $this->assertTrue($container->hasAlias(SubscribeStorageInterface::class));
        $this->assertSame('CustomStorage', (string) $container->getAlias(SubscribeStorageInterface::class));
    }

    public function testPrependGraphQLBundleAndMercureBundleEnabled(): void
    {
        $extension = new Extension();
        $this->assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container = $this->getMockBuilder(ContainerBuilder::class)
            ->setMethods(['hasExtension', 'prependExtensionConfig'])
            ->getMock();
        $container->expects($this->at(0))
            ->method('hasExtension')
            ->with('mercure')
            ->willReturn(true);

        $container->expects($this->at(1))
            ->method('prependExtensionConfig')
            ->with('overblog_graphql_subscription', [
                'mercure_hub' => [
                    'publisher' => [
                        'handler_id' => 'mercure.hub.default.publisher',
                    ],
                ],
            ]);

        $container->expects($this->at(2))
            ->method('hasExtension')
            ->with('overblog_graphql')
            ->willReturn(true);

        $container->expects($this->at(3))
            ->method('prependExtensionConfig')
            ->with('overblog_graphql_subscription', [
                'graphql_executor' => 'overblog_graphql.executor::execute',
                'schema_builder' => 'Overblog\\GraphQLBundle\\Request\\Executor::getSchema',
            ]);
        $extension->prepend($container);
    }

    public function testPrependGraphQLBundleApiPlatformAndMercureBundleEnabled(): void
    {
        $extension = new Extension();
        $this->assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container = $this->getMockBuilder(ContainerBuilder::class)
            ->setMethods(['hasExtension', 'prependExtensionConfig'])
            ->getMock();
        $container->expects($this->at(0))
            ->method('hasExtension')
            ->with('mercure')
            ->willReturn(true);

        $container->expects($this->at(1))
            ->method('prependExtensionConfig')
            ->with('overblog_graphql_subscription', [
                'mercure_hub' => [
                    'publisher' => [
                        'handler_id' => 'mercure.hub.default.publisher',
                    ],
                ],
            ]);

        $container->expects($this->at(2))
            ->method('hasExtension')
            ->with('overblog_graphql')
            ->willReturn(false);

        $container->expects($this->at(3))
            ->method('hasExtension')
            ->with('api_platform')
            ->willReturn(true);

        $container->expects($this->at(4))
            ->method('prependExtensionConfig')
            ->with('overblog_graphql_subscription', [
                'graphql_executor' => 'api_platform.graphql.executor::executeQuery',
                'schema_builder' => 'api_platform.graphql.schema_builder::getSchema',
            ]);
        $extension->prepend($container);
    }

    public function getProviderDataProvider(): iterable
    {
        yield ['publish', JwtPublishProvider::class];
        yield ['subscribe', JwtSubscribeProvider::class];
    }

    private function load(?array $config = null)
    {
        $config = $config ?? self::getMinimalConfiguration();
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', self::PROJECT_DIR);
        (new Extension())->load($config, $container);

        return $container;
    }

    private function assertDefinition(ContainerBuilder $container, string $serviceID, ?string $expectedClass = null, ?array $expectedTags = null): void
    {
        $this->assertTrue($container->hasDefinition($serviceID));
        if ($expectedClass) {
            $this->assertDefinitionClass($container, $serviceID, $expectedClass);
        }
        if (null !== $expectedTags) {
            $this->assertSame($expectedTags, $container->getDefinition($serviceID)->getTags());
        }
    }

    private function assertDefinitionNotExists(ContainerBuilder $container, string $serviceID): void
    {
        $this->assertFalse($container->hasDefinition($serviceID));
    }

    private function assertDefinitionClass(ContainerBuilder $container, string $serviceID, ?string $expectedClass = null): void
    {
        $this->assertSame(
            $container->getDefinition($serviceID)->getClass(),
            $expectedClass
        );
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
                'graphql_executor' => 'graphql_executor_handler',
            ],
        ];
    }
}
