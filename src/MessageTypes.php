<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

/**
 * @see https://github.com/apollographql/subscriptions-transport-ws/blob/master/src/message-types.ts
 */
final class MessageTypes
{
    public const GQL_CONNECTION_INIT = 'connection_init'; // Client -> Server
    public const GQL_CONNECTION_ACK = 'connection_ack'; // Server -> Client
    public const GQL_CONNECTION_ERROR = 'connection_error'; // Server -> Client

    // NOTE: The keep alive message type does not follow the standard due to connection optimizations
    public const GQL_CONNECTION_KEEP_ALIVE = 'ka'; // Server -> Client

    public const GQL_CONNECTION_TERMINATE = 'connection_terminate'; // Client -> Server
    public const GQL_START = 'start'; // Client -> Server
    public const GQL_DATA = 'data'; // Server -> Client
    public const GQL_ERROR = 'error'; // Server -> Client
    public const GQL_COMPLETE = 'complete'; // Server -> Client
    public const GQL_STOP = 'stop'; // Client -> Server

    public const CLIENT_MESSAGE_TYPES = [
        self::GQL_CONNECTION_INIT,
        self::GQL_CONNECTION_TERMINATE,
        self::GQL_START,
        self::GQL_STOP,
    ];
}
