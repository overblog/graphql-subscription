<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Provider;

final class JwtPublishProvider extends AbstractJwtProvider
{
    public function __invoke(): string
    {
        return $this->generateJWT('publish', ['*']);
    }
}
