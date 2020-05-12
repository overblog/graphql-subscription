<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\Event;

// TODO(mcg-web): remove hack after migrating Symfony >= 4.3
use Symfony\Contracts\EventDispatcher\Event;

if (EventDispatcherVersionHelper::isForLegacy()) {
    final class SubscriptionExtraEvent extends \Symfony\Component\EventDispatcher\Event
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
} else {
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
}
