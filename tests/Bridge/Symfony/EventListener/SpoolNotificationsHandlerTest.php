<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests\Bridge\Symfony\EventListener;

use Overblog\GraphQLSubscription\Bridge\Symfony\EventListener\SpoolNotificationsHandler;
use Overblog\GraphQLSubscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;

class SpoolNotificationsHandlerTest extends TestCase
{
    public function testOnKernelTerminate(): void
    {
        $subscriptionManager = $this->getMockBuilder(SubscriptionManager::class)
            ->setMethods(['processNotificationsSpool'])
            ->disableOriginalConstructor()
            ->getMock();
        $subscriptionManager->expects($this->once())
            ->method('processNotificationsSpool');
        (new SpoolNotificationsHandler($subscriptionManager))->onKernelTerminate();
    }
}
