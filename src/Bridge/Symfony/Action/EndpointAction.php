<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\Action;

use Overblog\GraphQLSubscription\Bridge\Symfony\Event\SubscriptionExtraEvent;
use Overblog\GraphQLSubscription\MessageTypes;
use Overblog\GraphQLSubscription\Request\JsonParser;
use Overblog\GraphQLSubscription\SubscriptionManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class EndpointAction
{
    private $requestParser;
    private $responseHandler;

    public function __construct(
        ?callable $requestParser = null,
        ?callable $responseHandler = null
    ) {
        $this->requestParser = $requestParser ?? [$this, 'parseRequest'];
        $this->responseHandler = $responseHandler ?? [$this, 'createJsonResponse'];
    }

    public function __invoke(
        Request $request,
        SubscriptionManager $realtimeNotifier,
        ?EventDispatcherInterface $dispatcher = null,
        ?string $schemaName = null
    ): Response {
        return ($this->responseHandler)($request, function (Request $request) use ($schemaName, $realtimeNotifier, $dispatcher): array {
            [$type, $id, $payload] = ($this->requestParser)($request);
            try {
                $extra = [];
                if ($dispatcher && MessageTypes::GQL_START === $type) {
                    $extra = new \ArrayObject($extra);
                    $dispatcher->dispatch(SubscriptionExtraEvent::class, new SubscriptionExtraEvent($extra));
                    $extra = $extra->getArrayCopy();
                }

                return $realtimeNotifier->handle(
                    \compact('type', 'id', 'payload'),
                    $schemaName,
                    $extra
                );
            } catch (\InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }
        });
    }

    private function createJsonResponse(Request $request, callable $payloadHandler): JsonResponse
    {
        return new JsonResponse($payloadHandler($request));
    }

    private function parseRequest(Request $request): array
    {
        return (new JsonParser())($request->headers->get('content-type'), $request->getContent());
    }
}