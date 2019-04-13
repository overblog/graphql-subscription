<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

final class MessageTypes
{
    public const GQL_START = 'start'; // Client -> Server
    public const GQL_STOP = 'stop'; // Client -> Server
    public const GQL_DATA = 'data'; // Server -> Client
    public const GQL_ERROR = 'error'; // Server -> Client
    public const GQL_SUCCESS = 'success'; // Server -> Client

    public const CLIENT_MESSAGE_TYPES = [
        self::GQL_START,
        self::GQL_STOP,
    ];
}
