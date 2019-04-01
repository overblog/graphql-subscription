<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Storage;

use Overblog\GraphQLSubscription\Entity\Subscriber;

interface SubscribeStorageInterface
{
    /**
     * @param Subscriber $subscriber
     *
     * @return bool
     *
     * @throws \RuntimeException if could not store subscription
     */
    public function store(Subscriber $subscriber): bool;

    /**
     * Return an array of subscribers for given channel an schema name.
     *
     * @param string $channel
     * @param string $schemaName
     *
     * @return Subscriber[]|iterable|\Generator
     */
    public function findSubscribersByChannelAndSchemaName(string $channel, ?string $schemaName): iterable;
}
