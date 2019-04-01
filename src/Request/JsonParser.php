<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Request;

final class JsonParser
{
    public function __invoke(string $contentType, ?string $requestBody): array
    {
        if (false !== \strpos($contentType, ';')) {
            $contentType = \explode(';', (string) $contentType, 2)[0];
        }

        if ('application/json' === \strtolower($contentType)) {
            $input = \json_decode($requestBody, true);
            if (JSON_ERROR_NONE !== \json_last_error()) {
                throw new \RuntimeException(\sprintf(
                   'Could not decode request body %s cause %s',
                   \json_encode($requestBody),
                   \json_encode(\json_last_error_msg())
                ));
            }

            $type = $input['type'] ?? null;
            $id = $input['id'] ? (string) $input['id'] : null;
            $payload = $input['payload'] ?? null;

            return [$type, $id, $payload];
        } else {
            throw new \RuntimeException(\sprintf(
                'Only "application/json" content-type is managed by parser but got %s.',
                \json_encode($contentType)
            ));
        }
    }
}
