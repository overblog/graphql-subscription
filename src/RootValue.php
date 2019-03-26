<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

use Overblog\GraphQLSubscription\Model\Subscriber;

final class RootValue
{
    private $propagationStopped = false;
    private $payload;
    private $subscriber;

    public function __construct($payload, Subscriber $subscriber)
    {
        $this->payload = $payload;
        $this->subscriber = $subscriber;
    }

    public function isPropagationStopped()
    {
        return $this->propagationStopped;
    }

    public function stopPropagation()
    {
        $this->propagationStopped = true;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getSubscriber(): Subscriber
    {
        return $this->subscriber;
    }
}
