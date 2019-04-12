<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests\Bridge\Symfony\Action;

use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Overblog\GraphQLSubscription\Bridge\Symfony\Action\EndpointAction;
use Overblog\GraphQLSubscription\Bridge\Symfony\Event\SubscriptionExtraEvent;
use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\MessageTypes;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\MemorySubscriptionStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Overblog\GraphQLSubscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

class EndpointActionTest extends TestCase
{
    public const SECRET_SUBSCRIBER_KEY = 'verySecretKey';

    private const SUBSCRIPTION_QUERY = <<<'GQL'
subscription {
  inbox(roomName: "foo") {
    roomId
    body
    timestamp
  }
}
GQL;

    /**
     * @param string|null $expectedSchemaName
     * @dataProvider getSchemaNameDataProvider
     */
    public function testCreateAction(?string $expectedSchemaName): void
    {
        [$response, $storage] = $this->processResponse($expectedPayload = ['data' => ['inbox' => null]], $expectedSchemaName);
        $result = $response->getContent();
        $actual = \json_decode($result, true);
        $this->assertRegExp('@^https://graphql.org/subscriptions/[a-zA-Z0-9]{12}@', $actual['topic']);
        $actualParseToken = (new Parser())->parse($actual['accessToken']);
        $this->assertSame(['subscribe' => [$actual['topic']]], (array) $actualParseToken->getClaim('mercure'));
        $this->assertTrue($actualParseToken->verify(new Sha256(), self::SECRET_SUBSCRIBER_KEY));
        $this->assertSame($expectedPayload, $actual['payload'], $result);
        $this->assertCount(1, $storage->findSubscribersByChannelAndSchemaName('inbox', $expectedSchemaName));
        $this->assertInstanceOf(Subscriber::class, $storage->findSubscribersByChannelAndSchemaName('inbox', $expectedSchemaName)->current());
    }

    /**
     * @param string|null $expectedSchemaName
     * @dataProvider getSchemaNameDataProvider
     */
    public function testSubscriptionExtraEvent(?string $expectedSchemaName): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SubscriptionExtraEvent::class, function (SubscriptionExtraEvent $event): void {
            $event->getExtra()['foo'] = 'bar';
        });

        [, $storage] = $this->processResponse(
            ['data' => ['inbox' => null]],
            $expectedSchemaName,
            self::SUBSCRIPTION_QUERY,
            $dispatcher
        );
        /** @var Subscriber $subscriber */
        $subscriber = $storage->findSubscribersByChannelAndSchemaName('inbox', $expectedSchemaName)->current();
        $this->assertSame('bar', $subscriber->getExtras()['foo']);
    }

    /**
     * @param string|null $expectedSchemaName
     * @dataProvider getSchemaNameDataProvider
     */
    public function testCreateActionFailed(?string $expectedSchemaName): void
    {
        $graphqlPayload = [
            'errors' => [
                [
                    'message' => 'Cannot query field "fake" on type "Subscription".',
                    'extensions' => ['category' => 'graphql'],
                    'locations' => [['line' => 1, 'column' => 16]],
                ],
            ],
        ];

        [$response, $storage] = $this->processResponse(
            $graphqlPayload, $expectedSchemaName, 'subscription { fake }'
        );

        $result = $response->getContent();
        $actual = \json_decode($result, true);
        $expectedPayload = [
            'type' => 'error',
            'payload' => $graphqlPayload,
        ];
        $this->assertSame($expectedPayload, $actual);
        $this->assertCount(0, $storage->findSubscribersByChannelAndSchemaName('inbox', $expectedSchemaName));
    }

    public function getSchemaNameDataProvider(): iterable
    {
        yield [null];
        yield ['foo'];
    }

    private function processResponse(
        array $expectedPayload,
        ?string $expectedSchemaName,
        string $expectedQuery = self::SUBSCRIPTION_QUERY,
        ?EventDispatcher $dispatcher = null
    ): array {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            \json_encode([
                'id' => 1,
                'type' => MessageTypes::GQL_START,
                'payload' => ['query' => $expectedQuery], ]
            )
        );
        $request->setMethod('POST');
        $request->headers->set('Content-Type', 'application/json;charset=utf8');
        $action = $this->getEntryPoint();

        return [
            $action(
                $request,
                $this->getSubscriptionManager(
                    $storage = new MemorySubscriptionStorage(),
                    function (
                        ?string $schemaName,
                        string $query,
                        $rootValue = null,
                        $context = null,
                        ?array $variableValues = null,
                        ?string $operationName = null
                    ) use ($expectedSchemaName, $expectedPayload, $expectedQuery): array {
                        static $previousCall = 0;
                        $this->assertSame(0, $previousCall);
                        $this->assertSame($expectedSchemaName, $schemaName);
                        $this->assertNull($variableValues);
                        $this->assertNull($operationName);
                        $this->assertNull($rootValue);
                        $this->assertSame($expectedQuery, $query);

                        ++$previousCall;

                        return $expectedPayload;
                    }
                ),
                $dispatcher,
                $expectedSchemaName
            ),
            $storage,
        ];
    }

    private function getSubscriptionManager(SubscribeStorageInterface $storage, callable $executor): SubscriptionManager
    {
        return new SubscriptionManager(
            function (): void {
                $this->fail('Publisher should never be execute in create action');
            },
            $storage,
            $executor,
            'https://graphql.org/subscriptions/{id}',
            new JwtSubscribeProvider(self::SECRET_SUBSCRIBER_KEY)
        );
    }

    private function getEntryPoint(): EndpointAction
    {
        return new EndpointAction();
    }
}
