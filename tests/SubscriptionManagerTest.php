<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests;

use GraphQL\Executor\ExecutionResult;
use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\RootValue;
use Overblog\GraphQLSubscription\Storage\MemorySubscriptionStorage;
use Overblog\GraphQLSubscription\SubscriptionManager;
use Overblog\GraphQLSubscription\Update;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SubscriptionManagerTest extends TestCase
{
    public const HUB_URL = 'https://example.test/hub';
    public const JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.OPker5jU6ePTLigtCI8WbYgOpfNvI-dClddsjsiFXh4';

    public function testNotifyWithoutBus(): void
    {
        $executor = $this->createCallableMock();
        $publisher = $this->createPublisher([
            'topic' => 'https://graphql.org/subscriptions/myID',
            'data' => '{"type":"data","id":"myID","payload":{"data":{"inbox":{"message":"hello word!"}}}}',
            'target' => 'https://graphql.org/subscriptions/myID',
        ]);
        $subscriptionManager = $this->createSubscriptionManager($publisher, $executor);
        $executor
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (?string $schemaName, $source, ?RootValue $rootValue) {
                return new ExecutionResult(['inbox' => $rootValue->getPayload()]);
            })
        ;
        $subscriptionManager->notify('inbox', ['message' => 'hello word!']);
        $subscriptionManager->processNotificationsSpool(false);
    }

    public function testEventStopPropagation(): void
    {
        $executor = $this->createCallableMock();
        $responseFactory = $this->createCallableMock();
        $httpClient = new MockHttpClient($responseFactory);
        $subscriptionManager = $this->createSubscriptionManager(
            $this->createPublisher([], $httpClient),
            $executor
        );
        $executor
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (?string $schemaName, $source, ?RootValue $rootValue) {
                $rootValue->stopPropagation();

                return new ExecutionResult(['inbox' => $rootValue->getPayload()]);
            })
        ;
        $responseFactory
            ->expects($this->never())
            ->method('__invoke');
        $subscriptionManager->notify('inbox', []);
        $subscriptionManager->processNotificationsSpool(false);
    }

    public function testNotificationShouldBeSentOnlyOnProcessSPoolWithoutBus(): void
    {
        $executor = $this->createCallableMock();
        $subscriptionManager = $this->createSubscriptionManager($this->createPublisher(), $executor);
        $executor
            ->expects($this->never())
            ->method('__invoke')
        ;
        $subscriptionManager->notify('inbox', []);
        $subscriptionManager->notify('inbox', []);
    }

    public function testNotifyWithBus(): void
    {
        $subscriptionManager = $this->createSubscriptionManager($this->createPublisher(), $this->createCallableMock());
        $messageBus = $this->getMockBuilder(MessageBus::class)->setMethods(['dispatch'])->getMock();
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with(new Update('a:3:{s:7:"payload";a:1:{s:7:"message";s:3:"Hi!";}s:10:"schemaName";s:8:"mySchema";s:7:"channel";s:5:"inbox";}'))
            ->willReturn(new Envelope(new \stdClass()));

        $subscriptionManager->setBus($messageBus);
        $subscriptionManager->notify('inbox', ['message' => 'Hi!'], 'mySchema');
    }

    public function testInvoker(): void
    {
        $executor = $this->createCallableMock();
        $publisher = $this->createPublisher([
            'topic' => 'https://graphql.org/subscriptions/myID',
            'data' => '{"type":"data","id":"myID","payload":{"data":{"inbox":{"message":"Hi!"}}}}',
            'target' => 'https://graphql.org/subscriptions/myID',
        ]);
        $subscriptionManager = $this->createSubscriptionManager($publisher, $executor);
        $executor
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (?string $schemaName, $source, ?RootValue $rootValue) {
                return new ExecutionResult(['inbox' => $rootValue->getPayload()]);
            })
        ;
        $subscriptionManager(new Update(\serialize([
            'payload' => ['message' => 'Hi!'],
            'schemaName' => null,
            'channel' => 'inbox',
        ])));
    }

    private function createSubscriptionManager(callable $publisher, $executor, ?array $storage = null): SubscriptionManager
    {
        return new SubscriptionManager(
            $publisher,
            new MemorySubscriptionStorage($storage ?? [
                new Subscriber(
                    'myID',
                    'https://graphql.org/subscriptions/myID',
                    'subscription { inbox { message } }',
                    'inbox',
                    null,
                    null,
                    null
                ),
            ]),
            $executor,
            'https://graphql.org/subscriptions/{id}',
            function (): void {
            }
        );
    }

    private function createCallableMock()
    {
        return $this->createPartialMock(\stdClass::class, ['__invoke']);
    }

    private function createPublisher(array $expectedPostData = [], HttpClientInterface $httpClient = null, ?callable $jwtProvider = null, ?string $hubUrl = self::HUB_URL): Publisher
    {
        return new Publisher(
            $hubUrl,
            $jwtProvider ?? function (): string {
                return self::JWT;
            },
            $httpClient ?? new MockHttpClient(function (string $method, string $url, array $options) use ($expectedPostData): ResponseInterface {
                \parse_str($options['body'], $actualPostData);
                $this->assertSame($expectedPostData, $actualPostData);

                return new MockResponse('myID');
            })
        );
    }
}
