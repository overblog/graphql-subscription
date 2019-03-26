<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\Action;

use Overblog\GraphQLSubscription\Event\SubscriptionExtraEvent;
use Overblog\GraphQLBundle\Controller\CorsResponseHandlerTrait;
use Overblog\GraphQLSubscription\RealtimeNotifier;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SubscriptionAction
{
    use CorsResponseHandlerTrait;

    private $jwtSubscribeProvider;
    private $requestParser;
    private $responseHandler;

    public function __construct(
        callable $jwtSubscribeProvider,
        callable $requestParser,
        ?callable $responseHandler = null
    ) {
        $this->jwtSubscribeProvider = $jwtSubscribeProvider;
        $this->requestParser = $requestParser;
        $this->responseHandler = $responseHandler ?? [$this, 'createJsonResponses'];
    }

    public function __invoke(
        Request $request,
        RealtimeNotifier $realtimeNotifier,
        ?EventDispatcherInterface $dispatcher,
        ?string $schemaName = null
    ): Response {
        return ($this->responseHandler)($request, function (Request $request) use ($schemaName, $realtimeNotifier, $dispatcher): array {
            $payload = ($this->requestParser)($request);
            try {
                $extra = [];
                if ($dispatcher) {
                    $extra = new \ArrayObject($extra);
                    $dispatcher->dispatch(SubscriptionExtraEvent::class, new SubscriptionExtraEvent($extra));
                    $extra = $extra->getArrayCopy();
                }

                return $realtimeNotifier->handleStart(
                    $payload,
                    $this->jwtSubscribeProvider,
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
}
