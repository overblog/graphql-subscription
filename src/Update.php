<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

final class Update
{
    private $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function getData(): string
    {
        return $this->data;
    }
}
