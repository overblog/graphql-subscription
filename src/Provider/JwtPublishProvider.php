<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Provider;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Hmac\Sha256;

final class JwtPublishProvider
{
    private $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    public function __invoke(): string
    {
        return (string) (new Builder())
            ->set('mercure', ['publish' => ['*']])
            ->sign(new Sha256(), $this->secretKey)
            ->getToken();
    }
}
