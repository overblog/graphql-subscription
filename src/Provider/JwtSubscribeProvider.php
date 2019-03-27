<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Provider;

final class JwtSubscribeProvider extends AbstractJwtProvider
{
    public function __invoke(string $target): string
    {
        return $this->generateJWT('subscribe', [$target]);
    }
}
