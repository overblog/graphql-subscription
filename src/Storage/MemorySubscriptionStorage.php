<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Storage;

use Overblog\GraphQLSubscription\Entity\Subscriber;

/**
 * This class should be used only for tests.
 */
final class MemorySubscriptionStorage implements SubscribeStorageInterface
{
    /** @var Subscriber[] */
    private $storage = [];

    public function __construct(iterable $storage = null)
    {
        if (null !== $storage) {
            foreach ($storage as $id => $subscriber) {
                $this->store((string) $id, $subscriber);
            }
        }
    }

    public function store(string $id, Subscriber $subscriber): bool
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
}
