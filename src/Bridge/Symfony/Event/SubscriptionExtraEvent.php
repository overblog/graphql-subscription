<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\Event;

use Symfony\Component\EventDispatcher\Event;

final class SubscriptionExtraEvent extends Event
{
    /** @var \ArrayObject */
    private $extra;

    /**
     * @param \ArrayObject $extra
     */
    public function __construct(\ArrayObject $extra)
    {
        $this->extra = $extra;
    }

    /**
     * @return \ArrayObject
     */
    public function getExtra(): \ArrayObject
    {
        return $this->extra;
    }
}
