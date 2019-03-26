<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Storage;

use Overblog\GraphQLSubscription\Model\Subscriber;

interface SubscribeStorageInterface
{
    /**
     * @param Subscriber $subscriber
     *
     * @throws \RuntimeException if could not store subscription
     *
     * @return bool
     */
    public function store(Subscriber $subscriber): bool;

    /**
     * Return an array of subscribers for given channel an schema name.
     *
     * @param string $channel
     * @param string $schemaName
     *
     * @return Subscriber[]|iterable
     */
    public function findSubscribersByChannelAndSchemaName(string $channel, ?string $schemaName): iterable;
}
