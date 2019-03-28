<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests\Bridge\Symfony\Action;

use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Overblog\GraphQLSubscription\Bridge\Symfony\Action\SubscriptionAction;
use Overblog\GraphQLSubscription\Bridge\Symfony\Event\SubscriptionExtraEvent;
use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\RealtimeNotifier;
use Overblog\GraphQLSubscription\Storage\MemorySubscriptionStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

class SubscriptionActionTest extends TestCase
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
        $actualParseToken = (new Parser())->parse($actual['token']);
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
        string $query = self::SUBSCRIPTION_QUERY,
        ?EventDispatcher $dispatcher = null
    ): array {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            $query
        );
        $request->setMethod('POST');
        $request->headers->set('Content-Type', 'application/graphql;charset=utf8');
        $action = $this->getEntryPoint(
            $expectedRequestParams = [
                'query' => $query,
                'variables' => null,
                'operationName' => null,
            ]
        );

        return [
            $action(
                $request,
                $this->getRealtimeNotifier(
                    $storage = new MemorySubscriptionStorage(),
                    function (?string $schemaName, array $requestParams, $rootValue = null) use ($expectedSchemaName, $expectedPayload, $expectedRequestParams): array {
                        static $previousCall = 0;
                        $this->assertSame(0, $previousCall);
                        $this->assertSame($expectedSchemaName, $schemaName);
                        $this->assertNull($rootValue);
                        $this->assertSame($expectedRequestParams, $requestParams);

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

    private function getRealtimeNotifier(SubscribeStorageInterface $storage, callable $executor): RealtimeNotifier
    {
        return new RealtimeNotifier(
            function (): void {
                $this->fail('Publisher should never be execute in create action');
            },
            $storage,
            $executor,
            'https://graphql.org/subscriptions/{id}'
        );
    }

    private function getEntryPoint(array $requestPayload, callable $jwtProvider = null, callable $responseHandler = null): SubscriptionAction
    {
        return new SubscriptionAction(
            $jwtProvider ?? new JwtSubscribeProvider(self::SECRET_SUBSCRIBER_KEY),
            function () use ($requestPayload) {
                return $requestPayload;
            },
            $responseHandler
        );
    }
}
