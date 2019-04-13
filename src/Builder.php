<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use Overblog\GraphQLSubscription\Provider\JwtPublishProvider;
use Overblog\GraphQLSubscription\Provider\JwtSubscribeProvider;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use Overblog\GraphQLSubscription\Storage\SubscribeStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Publisher;
use Symfony\Component\Mercure\Publisher as MercurePublisher;
use Symfony\Component\Messenger\MessageBusInterface;

class Builder
{
    /** @var string */
    private $hubUrl;

    /** @var string */
    private $topicUrlPattern;

    /** @var callable */
    private $executorHandler;

    /** @var Publisher|null */
    private $publisher;

    /** @var callable|null */
    private $publisherHttpClient = null;

    /** @var callable|null */
    private $publisherProvider = null;

    /** @var string|null */
    private $publisherSecretKey = null;

    /** @var callable|null */
    private $subscriberProvider = null;

    /** @var string|null */
    private $subscriberSecretKey = null;

    /** @var MessageBusInterface|null */
    private $messengerBus = null;

    /** @var SubscribeStorageInterface|null */
    private $subscribeStorage = null;

    /** @var string|null */
    private $subscribeStoragePath = null;

    /** @var LoggerInterface|null */
    private $logger = null;

    /** @var callable|null */
    private $schemaBuilder = null;

    /** @var Schema|null */
    private $schema = null;

    public function setHubUrl(string $hubUrl): self
    {
        $this->hubUrl = $hubUrl;

        return $this;
    }

    public function setTopicUrlPattern(string $topicUrlPattern): self
    {
        $this->topicUrlPattern = $topicUrlPattern;

        return $this;
    }

    public function setExecutorHandler(callable $executorHandler): self
    {
        $this->executorHandler = $executorHandler;

        return $this;
    }

    public function setPublisher(?Publisher $publisher): self
    {
        $this->publisher = $publisher;

        return $this;
    }

    public function setPublisherHttpClient(?callable $publisherHttpClient): self
    {
        $this->publisherHttpClient = $publisherHttpClient;

        return $this;
    }

    public function setPublisherProvider(?callable $publisherProvider): self
    {
        $this->publisherProvider = $publisherProvider;

        return $this;
    }

    public function setPublisherSecretKey(?string $publisherSecretKey): self
    {
        $this->publisherSecretKey = $publisherSecretKey;

        return $this;
    }

    public function setSubscriberProvider(?callable $subscriberProvider): self
    {
        $this->subscriberProvider = $subscriberProvider;

        return $this;
    }

    public function setSubscriberSecretKey(?string $subscriberSecretKey): self
    {
        $this->subscriberSecretKey = $subscriberSecretKey;

        return $this;
    }

    public function setMessengerBus(?MessageBusInterface $messengerBus): self
    {
        $this->messengerBus = $messengerBus;

        return $this;
    }

    public function setSubscribeStorage(?SubscribeStorageInterface $subscribeStorage): self
    {
        $this->subscribeStorage = $subscribeStorage;

        return $this;
    }

    public function setSubscribeStoragePath(?string $subscribeStoragePath): self
    {
        $this->subscribeStoragePath = $subscribeStoragePath;

        return $this;
    }

    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function setSchemaBuilder(?callable $schemaBuilder): self
    {
        $this->schemaBuilder = $schemaBuilder;

        return $this;
    }

    public function setSchema(?Schema $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function getSubscriptionManager(): SubscriptionManager
    {
        $publisher = $this->publisher;
        if (null === $publisher) {
            $publisher = new MercurePublisher(
                $this->hubUrl,
                $this->publisherProvider ?? new JwtPublishProvider($this->publisherSecretKey),
                $this->publisherHttpClient
            );
        }
        $subscribeStorage = $this->subscribeStorage ?? new FilesystemSubscribeStorage(
            $this->subscribeStoragePath ?? \sys_get_temp_dir().'/graphql-subscriptions'
            );
        $subscriberProvider = $this->subscriberProvider ?? new JwtSubscribeProvider($this->subscriberSecretKey);
        $schemaBuilder = $this->schemaBuilder;
        if (null === $schemaBuilder && null !== $this->schema) {
            $schema = $this->schema;
            $schemaBuilder = static function () use ($schema): Schema {
                return $schema;
            };
        }

        return new SubscriptionManager(
            $publisher,
            $subscribeStorage,
            $this->executorHandler ?? [GraphQL::class, 'executeQuery'],
            $this->topicUrlPattern,
            $subscriberProvider,
            $this->logger,
            $schemaBuilder
        );
    }
}
