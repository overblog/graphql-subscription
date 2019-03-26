<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Provider;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Hmac\Sha256;

final class JwtSubscribeProvider
{
    private $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * @param string $target
     *
     * @return string
     */
    public function __invoke(string $target): string
    {
        return (string) (new Builder())
            ->set('mercure', ['subscribe' => [$target]])
            ->sign(new Sha256(), $this->secretKey)
            ->getToken();
    }
}
