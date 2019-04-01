<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

use GraphQL\Error\SyntaxError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Mercure\Update as MercureUpdate;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubscriptionManager
{
    private $publisher;

    /** @var MessageBusInterface */
    private $bus;

    private $subscribeStorage;

    private $executor;

    private $topicUrlPattern;

    private $jwtSubscribeProvider;

    private $viaBus = false;

    private $notificationsSpool = [];

    private $logger;

    /**
     * RealtimeNotifier constructor.
     *
     * @param Publisher|callable        $publisher
     * @param SubscribeStorageInterface $subscribeStorage
     * @param callable                  $executor             should return the result payload
     * @param string                    $topicUrlPattern
     * @param callable                  $jwtSubscribeProvider
     * @param LoggerInterface|null      $logger
     */
    public function __construct(
        callable $publisher,
        SubscribeStorageInterface $subscribeStorage,
        callable $executor,
        string $topicUrlPattern,
        callable $jwtSubscribeProvider,
        ?LoggerInterface $logger = null
    ) {
        $this->publisher = $publisher;
        $this->executor = $executor;
        $this->subscribeStorage = $subscribeStorage;
        $this->validateTopicUrlPattern($topicUrlPattern);
        $this->topicUrlPattern = $topicUrlPattern;
        $this->jwtSubscribeProvider = $jwtSubscribeProvider;
        $this->logger = $logger ?? new NullLogger();
    }

    public function validateTopicUrlPattern(string $topicUrlPattern): void
    {
        if (false === \filter_var($topicUrlPattern, FILTER_VALIDATE_URL) || false === \strpos($topicUrlPattern, '{id}')) {
            throw new \InvalidArgumentException(\sprintf(
                'Topic url pattern should be a valid url and should contain the "{id}" replacement string but got %s.',
                \json_encode($topicUrlPattern)
            ));
        }
    }

    public function getExecutor(): callable
    {
        return $this->executor;
    }

    public function getSubscribeStorage(): SubscribeStorageInterface
    {
        return $this->subscribeStorage;
    }

    public function setBus(?MessageBusInterface $bus): self
    {
        $this->viaBus = null !== $bus;
        $this->bus = $bus;

        return $this;
    }

    public function notify(string $channel, $payload, string $schemaName = null): void
    {
        $data = ['payload' => $payload, 'schemaName' => $schemaName, 'channel' => $channel];
        if ($this->viaBus) {
            $update = new Update(\serialize($data));
            $this->bus->dispatch($update);
        } else {
            $this->notificationsSpool[] = $data;
        }
    }

    public function __invoke(Update $update): void
    {
        $this->handleData(\unserialize($update->getData()));
    }

    public function processNotificationsSpool(bool $catchException = true): void
    {
        foreach ($this->notificationsSpool as $data) {
            try {
                $this->handleData($data);
            } catch (\Throwable $e) {
                if (!$catchException) {
                    throw $e;
                }
                $this->logger->critical(
                    'Caught exception or error in %s',
                    [
                        'exception' => [
                            'file' => $e->getFile(),
                            'code' => $e->getCode(),
                            'message' => $e->getMessage(),
                            'line' => $e->getLine(),
                        ],
                    ]
                );
            }
        }
        $this->notificationsSpool = [];
    }

    public function handle(
        array $data,
        ?string $schemaName = null,
        ?array $extra = null
    ): ?array {
        $type = $data['type'] ?? null;

        switch ($type) {
            case MessageTypes::GQL_CONNECTION_INIT:
            case MessageTypes::GQL_CONNECTION_TERMINATE:
                return [
                    'type' => MessageTypes::GQL_CONNECTION_ACK,
                    'payload' => [],
                ];

            case MessageTypes::GQL_START:
                $payload = [
                    'query' => null,
                    'variables' => null,
                    'operationName' => null,
                ];

                if (\is_array($data['payload'])) {
                    $payload = \array_filter($data['payload']) + $payload;
                }

                return $this->handleStart(
                    $data['id'] ?? null,
                    $payload,
                    $schemaName,
                    $extra
                );

            case MessageTypes::GQL_STOP:
                return null;

            default:
                throw new \InvalidArgumentException(\sprintf(
                    'Only "%s" types are handle by "SubscriptionHandler".',
                    \implode('", ', MessageTypes::CLIENT_MESSAGE_TYPES)
                ));
        }
    }

    private function handleStart(
        ?string $subscriptionID,
        ?array $payload,
        ?string $schemaName = null,
        array $extra = []
    ): array {
        $result = ($this->executor)($schemaName, $payload);
        if ($result instanceof ExecutionResult) {
            $result = $result->toArray();
        }

        if (empty($result['errors'])) {
            $document = self::parseQuery($payload['query']);
            $operationDef = self::extractOperationDefinition($document, $payload['operationName']);
            $channel = self::extractSubscriptionChannel($operationDef);
            $id = $this->generateId($subscriptionID);
            $topic = $this->buildTopicUrl($id, $channel, $schemaName);

            $this->getSubscribeStorage()->store(new Subscriber(
                $id,
                $subscriptionID,
                $topic,
                $payload['query'],
                $channel,
                $payload['variables'],
                $payload['operationName'],
                $schemaName,
                $extra
            ));

            return [
                'type' => MessageTypes::GQL_DATA,
                'id' => $subscriptionID,
                'payload' => $result,
                'extensions' => [
                    'id' => $id,
                    'topic' => $topic,
                    'token' => ($this->jwtSubscribeProvider)($topic),
                ],
            ];
        } else {
            return [
                'type' => MessageTypes::GQL_ERROR,
                'payload' => $result,
            ];
        }
    }

    private function handleData(array $data): void
    {
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf(
                '"%s" require message to be an array or a json string but got %s',
                __METHOD__,
                \gettype($data)
            ));
        }

        $subscribers = $this->subscribeStorage
            ->findSubscribersByChannelAndSchemaName($data['channel'], $data['schemaName']);
        foreach ($subscribers as $subscriber) {
            $this->executeAndSendNotification($data['payload'], $subscriber);
        }
    }

    private function generateId(?string $subscriptionId): string
    {
        $sha1 = \sha1(\uniqid(\time().$subscriptionId.\random_int(0, \PHP_INT_MAX), true));

        return \substr($sha1, 0, 12);
    }

    private function buildTopicUrl(string $id, string $channel, ?string $schemaName): string
    {
        return \str_replace(
            ['{id}', '{channel}', '{schemaName}'],
            [$id, $channel, $schemaName],
            $this->topicUrlPattern
        );
    }

    private function executeAndSendNotification($payload, Subscriber $subscriber): void
    {
        $result = ($this->executor)(
            $subscriber->getSchemaName(),
            [
                'query' => $subscriber->getQuery(),
                'variables' => $subscriber->getVariables(),
                'operationName' => $subscriber->getOperationName(),
            ],
            $event = new RootValue($payload, $subscriber)
        );

        if (!$event->isPropagationStopped()) {
            $update = new MercureUpdate(
                $subscriber->getTopic(),
                \json_encode([
                    'type' => MessageTypes::GQL_DATA,
                    'payload' => $result,
                    'extensions' => [
                        'id' => $subscriber->getId(),
                        'topic' => $subscriber->getTopic(),
                    ],
                ]),
                [$subscriber->getTopic()]
            );
            ($this->publisher)($update);
        }
    }

    private static function parseQuery(string $query): DocumentNode
    {
        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e);
        }

        return $document;
    }

    private function extractSubscriptionChannel(OperationDefinitionNode $operationDefinitionNode): string
    {
        $selectionNodes = $operationDefinitionNode
            ->selectionSet
            ->selections;

        if (\count($selectionNodes) > 1) {
            throw new \InvalidArgumentException(\sprintf('Subscription operations must have exactly one root field.'));
        }

        return $selectionNodes[0]->name->value;
    }

    private static function extractOperationDefinition(DocumentNode $document, $operationName = null): OperationDefinitionNode
    {
        $operationNode = null;

        if ($document->definitions) {
            foreach ($document->definitions as $def) {
                if (!$def instanceof OperationDefinitionNode) {
                    continue;
                }

                if (!$operationName || (isset($def->name->value) && $def->name->value === $operationName)) {
                    $operationNode = $def;
                }
            }
        }

        if (null === $operationNode || 'subscription' !== $operationNode->operation) {
            throw new \InvalidArgumentException(\sprintf(
                'Operation should be of type subscription but %s given',
                isset($operationNode->operation) ? \json_encode($operationNode->operation) : 'none'
            ));
        }

        return $operationNode;
    }
}
