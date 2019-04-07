<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\EventListener;

use Overblog\GraphQLSubscription\SubscriptionManager;

final class SpoolNotificationsHandler
{
    private $subscriptionManager;

    public function __construct(SubscriptionManager $subscriptionManager)
    {
        $this->subscriptionManager = $subscriptionManager;
    }

    public function onKernelTerminate(): void
    {
        $this->subscriptionManager->processNotificationsSpool();
    }
}
