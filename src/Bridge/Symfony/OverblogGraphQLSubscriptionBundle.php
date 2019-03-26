<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony;

use Overblog\GraphQLSubscription\Bridge\Symfony\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class OverblogGraphQLSubscriptionBundle extends Bundle
{
    public function getContainerExtension()
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new Extension();
        }

        return $this->extension;
    }
}
