<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests;

use Overblog\GraphQLSubscription\Builder;
use Overblog\GraphQLSubscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    public function testMinimalBuild(): void
    {
        $manager = (new Builder())
            ->setHubUrl('https://example.test/hub')
            ->setTopicUrlPattern('https://graphql.org/subscriptions/{id}')
            ->setPublisherSecretKey('pubSecret')
            ->setSubscriberSecretKey('subSecret')
            ->setExecutorHandler(function (): void {})
            ->getSubscriptionManager();
        $this->assertInstanceof(SubscriptionManager::class, $manager);
    }
}
