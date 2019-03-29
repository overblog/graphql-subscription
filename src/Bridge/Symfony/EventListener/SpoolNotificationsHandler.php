<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\EventListener;

use Overblog\GraphQLSubscription\RealtimeNotifier;

final class SpoolNotificationsHandler
{
    private $realtimeNotifier;

    public function __construct(RealtimeNotifier $realtimeNotifier)
    {
        $this->realtimeNotifier = $realtimeNotifier;
    }

    public function onKernelTerminate(): void
    {
        $this->realtimeNotifier->processNotificationsSpool();
    }
}
