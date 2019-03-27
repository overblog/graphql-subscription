<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

use GraphQL\Error\SyntaxError;
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

final class RealtimeNotifier
{
    public const SUBSCRIPTION_START = 'start';
    public const SUBSCRIPTION_STOP = 'stop';
    public const SUBSCRIPTION_DATA = 'data';
    public const SUBSCRIPTION_ERROR = 'error';

    private $publisher;

    /** @var MessageBusInterface */
    private $bus;

    private $subscribeStorage;

    private $executor;

    private $topicUrlPattern;

    private $viaBus = false;

    private $notificationsSpool = [];

    private $logger;

    /**
     * RealtimeNotifier constructor.
     *
     * @param Publisher|callable        $publisher
     * @param SubscribeStorageInterface $subscribeStorage
     * @param callable                  $executor         should return the result payload as an array
     * @param string                    $topicUrlPattern
     * @param LoggerInterface|null      $logger
     */
    public function __construct(
        callable $publisher,
        SubscribeStorageInterface $subscribeStorage,
        callable $executor,
        string $topicUrlPattern,
        ?LoggerInterface $logger = null
    ) {
        $this->publisher = $publisher;
        $this->executor = $executor;
        $this->subscribeStorage = $subscribeStorage;
        $this->validateTopicUrlPattern($topicUrlPattern);
        $this->topicUrlPattern = $topicUrlPattern;
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

    public function setBus($bus): self
    {
        if ($bus instanceof MessageBusInterface) {
            $this->bus = $bus;
            $this->viaBus = true;
        } elseif (null === $bus) {
            $this->bus = $bus;
            $this->viaBus = false;
        } else {
            throw new \InvalidArgumentException(\sprintf(
                'Bus should be null or instance of "Symfony\Component\Messenger\MessageBusInterface" but got %s.',
                \is_object($bus) ? \get_class($bus) : \gettype($bus)
            ));
        }

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

    public function processNotificationsSpool(): void
    {
        foreach ($this->notificationsSpool as $data) {
            try {
                $this->handleData($data);
            } catch (\Throwable $e) {
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

    public function handleStart(
        array $payload,
        callable $jwtSubscribeProvider,
        ?string $schemaName = null,
        array $extra = [],
        callable $idGenerator = null
    ): array {
        $result = ($this->executor)($schemaName, $payload);
        if (empty($result['errors'])) {
            $document = self::parseQuery($payload['query']);
            $operationDef = self::extractOperationDefinition($document, $payload['operationName']);
            $channel = self::extractSubscriptionChannel($operationDef);

            $idGenerator = $idGenerator ?? [$this, 'generateSubscriberId'];
            $id = $idGenerator();
            $topic = $this->buildTopicUrl($id, $channel, $schemaName);

            $this->getSubscribeStorage()->store(new Subscriber(
                $id,
                $topic,
                $payload['query'],
                $channel,
                $payload['variables'],
                $payload['operationName'],
                $schemaName,
                $extra
            ));

            return [
                'type' => self::SUBSCRIPTION_START,
                'topic' => $topic,
                'token' => ($jwtSubscribeProvider)($topic),
                'payload' => $result,
            ];
        } else {
            return [
                'type' => self::SUBSCRIPTION_ERROR,
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

    private function generateSubscriberId(): string
    {
        return \substr(\sha1(\uniqid(\random_int(0, \getrandmax()).'', true)), 0, 12);
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
                'operationName' => $subscriber->getOperatorName(),
            ],
            $event = new RootValue($payload, $subscriber)
        );

        if (!$event->isPropagationStopped()) {
            $topic = $subscriber->getTopic();
            $update = new MercureUpdate(
                $topic,
                \json_encode([
                    'type' => static::SUBSCRIPTION_DATA,
                    'payload' => $result,
                ]),
                [$topic]
            );
            $this->pushUpdate($update);
        }
    }

    private function pushUpdate(MercureUpdate $update): void
    {
        $pusher = $this->publisher;
        $pusher($update);
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
