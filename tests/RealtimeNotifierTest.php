<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests;

use Overblog\GraphQLSubscription\Model\Subscriber;
use Overblog\GraphQLSubscription\RealtimeNotifier;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Overblog\GraphQLSubscription\Update;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;

class RealtimeNotifierTest extends TestCase
{
    private const URL = 'https://example.test/hub';
    private const JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.OPker5jU6ePTLigtCI8WbYgOpfNvI-dClddsjsiFXh4';

    private $expectedPostData = [];

    /** @var SubscribeStorageInterface */
    private $memorySubscribeStorage;

    /** @var MockObject|callable */
    private $executor;

    /** @var RealtimeNotifier */
    private $realtimeNotifier;

    public function setUp(): void
    {
        $publisher = new Publisher(
            self::URL,
            function (): string {
                return self::JWT;
            },
            function (string $url, string $jwt, string $postData): string {
                \parse_str($postData, $actualPostData);
                $this->assertSame($this->expectedPostData, $actualPostData);

                return 'myID';
            }
        );

        $this->memorySubscribeStorage = new class() implements SubscribeStorageInterface {
            /** @var Subscriber[] */
            private $storage = [];

            public function store(Subscriber $subscriber): bool
            {
                $this->storage[] = $subscriber;

                return true;
            }

            public function findSubscribersByChannelAndSchemaName(string $channel, ?string $schemaName): iterable
            {
                foreach ($this->storage as $subscriber) {
                    if ($subscriber->getChannel() === $channel && $subscriber->getSchemaName() === $schemaName) {
                        yield $subscriber;
                    }
                }
            }
        };
        $this->executor = $this->createPartialMock(\stdClass::class, ['__invoke']);

        $this->realtimeNotifier = new RealtimeNotifier(
            $publisher,
            $this->memorySubscribeStorage,
            $this->executor,
            'https://graphql.org/subscriptions/{id}'
        );
    }

    public function testNotifyWithoutBus(): void
    {
        $this->memorySubscribeStorage->store(
            new Subscriber(
                'myID',
            'https://graphql.org/subscriptions/myID',
            'subscription { inbox { message } }',
            'inbox',
            null,
            null,
            null
            )
        );
        $this->executor
            ->expects($this->once())
            ->method('__invoke')
            ->willReturn(['inbox' => ['message' => 'hello word!']])
        ;
        $this->realtimeNotifier->notify('inbox', []);
        $this->expectedPostData = [
            'topic' => 'https://graphql.org/subscriptions/myID',
            'data' => '{"type":"data","payload":{"data":{"inbox":{"message":"hello word!"}}}}',
            'target' => 'https://graphql.org/subscriptions/myID',
        ];
        $this->realtimeNotifier->processNotificationsSpool();
    }

    public function testNotificationShouldBeSentOnlyOnProcessSPoolWithoutBus(): void
    {
        $this->memorySubscribeStorage->store(
            new Subscriber(
                'myID',
                'https://graphql.org/subscriptions/myID',
                'subscription { inbox { message } }',
                'inbox',
                null,
                null,
                null
            )
        );
        $this->executor
            ->expects($this->never())
            ->method('__invoke')
        ;
        $this->realtimeNotifier->notify('inbox', []);
        $this->realtimeNotifier->notify('inbox', []);
    }

    public function testNotifyWithBus(): void
    {
        $messageBus = $this->getMockBuilder(MessageBus::class)->setMethods(['dispatch'])->getMock();
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with(new Update('a:3:{s:7:"payload";a:1:{s:5:"inbox";a:1:{s:7:"message";s:3:"Hi!";}}s:10:"schemaName";s:8:"mySchema";s:7:"channel";s:5:"inbox";}'))
            ->willReturn(new Envelope(new \stdClass()));

        $this->realtimeNotifier->setBus($messageBus);
        $this->realtimeNotifier->notify('inbox', ['inbox' => ['message' => 'Hi!']], 'mySchema');
    }
}
