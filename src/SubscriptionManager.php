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

/**
 * @final
 */
class SubscriptionManager
{
    private $publicHubUrl;

    private $publisher;

    /** @var MessageBusInterface */
    private $bus;

    private $subscribeStorage;

    private $executorHandler;

    private $topicUrlPattern;

    private $jwtSubscribeProvider;

    private $viaBus = false;

    private $notificationsSpool = [];

    private $logger;

    private $schemaBuilder;

    /**
     * @param Publisher|callable        $publisher
     * @param SubscribeStorageInterface $subscribeStorage
     * @param callable                  $executor             should return the result payload
     * @param string                    $topicUrlPattern
     * @param callable                  $jwtSubscribeProvider
     * @param LoggerInterface|null      $logger
     * @param callable|null             $schemaBuilder
     * @param string|null               $publicHubUrl
     */
    public function __construct(
        callable $publisher,
        SubscribeStorageInterface $subscribeStorage,
        callable $executor,
        string $topicUrlPattern,
        callable $jwtSubscribeProvider,
        ?LoggerInterface $logger = null,
        ?callable $schemaBuilder = null,
        ?string $publicHubUrl = null
    ) {
        $this->publisher = $publisher;
        $this->executorHandler = $executor;
        $this->subscribeStorage = $subscribeStorage;
        $this->validateTopicUrlPattern($topicUrlPattern);
        $this->topicUrlPattern = $topicUrlPattern;
        $this->jwtSubscribeProvider = $jwtSubscribeProvider;
        $this->logger = $logger ?? new NullLogger();
        $this->schemaBuilder = $schemaBuilder;
        $this->publicHubUrl = $publicHubUrl;
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

    public function getExecutorHandler(): callable
    {
        return $this->executorHandler;
    }

    public function getSubscribeStorage(): SubscribeStorageInterface
    {
        return $this->subscribeStorage;
    }

    public function setSchemaBuilder(?callable $schemaBuilder): self
    {
        $this->schemaBuilder = $schemaBuilder;

        return $this;
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
            case MessageTypes::GQL_START:
                $payload = [
                    'query' => '',
                    'variables' => null,
                    'operationName' => null,
                ];

                if (\is_array($data['payload'])) {
                    $payload = \array_filter($data['payload']) + $payload;
                }

                return $this->handleStart(
                    $payload['query'], $schemaName, $payload['variables'], $payload['operationName'], $extra
                );

            case MessageTypes::GQL_STOP:
                $deleted = isset($data['id']) ? $this->subscribeStorage->delete($data['id']) : false;

                return [
                    'type' => $deleted ? MessageTypes::GQL_SUCCESS : MessageTypes::GQL_ERROR,
                ];

            default:
                throw new \InvalidArgumentException(\sprintf(
                    'Only "%s" types are handle by "SubscriptionHandler".',
                    \implode('", ', MessageTypes::CLIENT_MESSAGE_TYPES)
                ));
        }
    }

    private function handleStart(
        string $query, ?string $schemaName, ?array $variableValues, ?string $operationName, ?array $extra
    ): array {
        $result = $this->executeQuery(
            $schemaName,
            $query,
            null,
            null,
            $variableValues,
            $operationName
        );

        if (empty($result['errors'])) {
            $document = self::parseQuery($query);
            $operationDef = self::extractOperationDefinition($document, $operationName);
            $channel = self::extractSubscriptionChannel($operationDef);
            $id = $this->generateId();
            $topic = $this->buildTopicUrl($id, $channel, $schemaName);

            $this->getSubscribeStorage()->store(new Subscriber(
                $id,
                $topic,
                $query,
                $channel,
                $variableValues,
                $operationName,
                $schemaName,
                $extra
            ));

            $result['extensions']['__sse'] = [
                'id' => $id,
                'topic' => $topic,
                'hubUrl' => $this->buildHubUrl($topic),
                'accessToken' => ($this->jwtSubscribeProvider)($topic),
            ];

            return $result;
        } else {
            return $result;
        }
    }

    private function handleData(array $data): void
    {
        $subscribers = $this->subscribeStorage
            ->findSubscribersByChannelAndSchemaName($data['channel'], $data['schemaName']);
        foreach ($subscribers as $subscriber) {
            $this->executeAndSendNotification($data['payload'], $subscriber);
        }
    }

    private function generateId(): string
    {
        $sha1 = \sha1(\uniqid(\time().\random_int(0, \PHP_INT_MAX), true));

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

    private function buildHubUrl(string $topic): ?string
    {
        if (null === $this->publicHubUrl) {
            return null;
        }

        $querySeparator = empty(\parse_url($this->publicHubUrl)['query']) ? '?' : '&';

        return \sprintf('%s%stopic=%s', $this->publicHubUrl, $querySeparator, \urlencode($topic));
    }

    private function executeQuery(
        ?string $schemaName,
        string $query,
        ?RootValue $rootValue = null,
        $context = null,
        ?array $variableValues = null,
        ?string $operationName = null
    ): array {
        $result = ($this->executorHandler)(
            $this->schemaBuilder ? ($this->schemaBuilder)($schemaName) : $schemaName,
            $query,
            $rootValue,
            $context,
            $variableValues,
            $operationName
        );

        if ($result instanceof ExecutionResult) {
            $result = $result->toArray();
        }

        return $result;
    }

    private function executeAndSendNotification($payload, Subscriber $subscriber): void
    {
        $result = $this->executeQuery(
            $subscriber->getSchemaName(),
            $subscriber->getQuery(),
            $rootValue = new RootValue($payload, $subscriber),
            null,
            $subscriber->getVariables(),
            $subscriber->getOperationName()
        );

        if (!$rootValue->isPropagationStopped()) {
            $update = new MercureUpdate(
                $subscriber->getTopic(),
                \json_encode([
                    'type' => MessageTypes::GQL_DATA,
                    'id' => $subscriber->getId(),
                    'payload' => $result,
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
