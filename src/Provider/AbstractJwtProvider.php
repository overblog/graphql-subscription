<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Provider;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Hmac\Sha256;

/**
 * @internal
 */
abstract class AbstractJwtProvider
{
    protected $secretKey;

    public function __construct(string $secretKey)
    {
        $this->checkRequirements();
        $this->secretKey = $secretKey;
    }

    protected function checkRequirements(): void
    {
        if (!\class_exists('Lcobucci\JWT\Builder')) {
            throw new \RuntimeException(\sprintf(
                'To use "%s" you must install "lcobucci/jwt" package using composer.',
                \get_class($this)
            ));
        }
    }

    protected function generateJWT(string $type, array $targets): string
    {
        return (string) (new Builder())
            ->set('mercure', [$type => $targets])
            ->sign(new Sha256(), $this->secretKey)
            ->getToken();
    }
}
