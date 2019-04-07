<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription;

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

    /**
     * @param string $hubUrl
     *
     * @return self
     */
    public function setHubUrl(string $hubUrl): self
    {
        $this->hubUrl = $hubUrl;

        return $this;
    }

    /**
     * @param string $topicUrlPattern
     *
     * @return self
     */
    public function setTopicUrlPattern(string $topicUrlPattern): self
    {
        $this->topicUrlPattern = $topicUrlPattern;

        return $this;
    }

    /**
     * @param callable $executorHandler
     *
     * @return self
     */
    public function setExecutorHandler(callable $executorHandler): self
    {
        $this->executorHandler = $executorHandler;

        return $this;
    }

    /**
     * @param Publisher|null $publisher
     *
     * @return self
     */
    public function setPublisher(?Publisher $publisher): self
    {
        $this->publisher = $publisher;

        return $this;
    }

    /**
     * @param callable|null $publisherHttpClient
     *
     * @return self
     */
    public function setPublisherHttpClient(?callable $publisherHttpClient): self
    {
        $this->publisherHttpClient = $publisherHttpClient;

        return $this;
    }

    /**
     * @param callable|null $publisherProvider
     *
     * @return self
     */
    public function setPublisherProvider(?callable $publisherProvider): self
    {
        $this->publisherProvider = $publisherProvider;

        return $this;
    }

    /**
     * @param string|null $publisherSecretKey
     *
     * @return self
     */
    public function setPublisherSecretKey(?string $publisherSecretKey): self
    {
        $this->publisherSecretKey = $publisherSecretKey;

        return $this;
    }

    /**
     * @param callable|null $subscriberProvider
     *
     * @return self
     */
    public function setSubscriberProvider(?callable $subscriberProvider): self
    {
        $this->subscriberProvider = $subscriberProvider;

        return $this;
    }

    /**
     * @param string|null $subscriberSecretKey
     *
     * @return self
     */
    public function setSubscriberSecretKey(?string $subscriberSecretKey): self
    {
        $this->subscriberSecretKey = $subscriberSecretKey;

        return $this;
    }

    /**
     * @param MessageBusInterface|null $messengerBus
     *
     * @return self
     */
    public function setMessengerBus(?MessageBusInterface $messengerBus): self
    {
        $this->messengerBus = $messengerBus;

        return $this;
    }

    /**
     * @param SubscribeStorageInterface|null $subscribeStorage
     *
     * @return self
     */
    public function setSubscribeStorage(?SubscribeStorageInterface $subscribeStorage): self
    {
        $this->subscribeStorage = $subscribeStorage;

        return $this;
    }

    /**
     * @param string|null $subscribeStoragePath
     *
     * @return self
     */
    public function setSubscribeStoragePath(?string $subscribeStoragePath): self
    {
        $this->subscribeStoragePath = $subscribeStoragePath;

        return $this;
    }

    /**
     * @param LoggerInterface|null $logger
     *
     * @return self
     */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

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

        return new SubscriptionManager(
            $publisher,
            $subscribeStorage,
            $this->executorHandler,
            $this->topicUrlPattern,
            $subscriberProvider,
            $this->logger
        );
    }
}
