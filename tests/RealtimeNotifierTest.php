<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests;

use GraphQL\Executor\ExecutionResult;
use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\RealtimeNotifier;
use Overblog\GraphQLSubscription\RootValue;
use Overblog\GraphQLSubscription\Storage\MemorySubscriptionStorage;
use Overblog\GraphQLSubscription\Update;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;

class RealtimeNotifierTest extends TestCase
{
    public const URL = 'https://example.test/hub';
    public const JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.OPker5jU6ePTLigtCI8WbYgOpfNvI-dClddsjsiFXh4';

    public function testNotifyWithoutBus(): void
    {
        $executor = $this->createExecutor();
        $publisher = $this->createPublisher([
            'topic' => 'https://graphql.org/subscriptions/myID',
            'data' => '{"type":"data","payload":{"data":{"inbox":{"message":"hello word!"}}}}',
            'target' => 'https://graphql.org/subscriptions/myID',
        ]);
        $realtimeNotifier = $this->createRealtimeNotifier($publisher, $executor);
        $executor
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (?string $schemaName, array $requestParams, ?RootValue $rootValue) {
                return new ExecutionResult(['inbox' => $rootValue->getPayload()]);
            })
        ;
        $realtimeNotifier->notify('inbox', ['message' => 'hello word!']);
        $realtimeNotifier->processNotificationsSpool(false);
    }

    public function testNotificationShouldBeSentOnlyOnProcessSPoolWithoutBus(): void
    {
        $executor = $this->createExecutor();
        $realtimeNotifier = $this->createRealtimeNotifier($this->createPublisher(), $executor);
        $executor
            ->expects($this->never())
            ->method('__invoke')
        ;
        $realtimeNotifier->notify('inbox', []);
        $realtimeNotifier->notify('inbox', []);
    }

    public function testNotifyWithBus(): void
    {
        $realtimeNotifier = $this->createRealtimeNotifier($this->createPublisher(), $this->createExecutor());
        $messageBus = $this->getMockBuilder(MessageBus::class)->setMethods(['dispatch'])->getMock();
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with(new Update('a:3:{s:7:"payload";a:1:{s:5:"inbox";a:1:{s:7:"message";s:3:"Hi!";}}s:10:"schemaName";s:8:"mySchema";s:7:"channel";s:5:"inbox";}'))
            ->willReturn(new Envelope(new \stdClass()));

        $realtimeNotifier->setBus($messageBus);
        $realtimeNotifier->notify('inbox', ['inbox' => ['message' => 'Hi!']], 'mySchema');
    }

    private function createRealtimeNotifier(callable $publisher, $executor, ?array $storage = null): RealtimeNotifier
    {
        $storage = $storage ?? [
            new Subscriber(
        'https://graphql.org/subscriptions/myID', 'subscription { inbox { message } }', 'inbox', null, null, null
            ),
        ];

        return new RealtimeNotifier(
            $publisher,
            new MemorySubscriptionStorage($storage),
            $executor,
            'https://graphql.org/subscriptions/{id}'
        );
    }

    private function createExecutor()
    {
        return $this->createPartialMock(\stdClass::class, ['__invoke']);
    }

    private function createPublisher(array $expectedPostData = [], ?callable $jwtProvider = null, ?string $hubUrl = self::URL): Publisher
    {
        return new Publisher(
            $hubUrl,
            $jwtProvider ?? function (): string {
                return self::JWT;
            },
            function (string $url, string $jwt, string $postData) use ($expectedPostData): string {
                \parse_str($postData, $actualPostData);
                $this->assertSame($expectedPostData, $actualPostData);

                return 'myID';
            }
        );
    }
}
