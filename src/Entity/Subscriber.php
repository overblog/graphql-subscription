<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Entity;

final class Subscriber implements \Serializable
{
    private $id;
    private $subscriptionId;
    private $topic;
    private $query;
    private $channel;
    private $variables;
    private $operationName;
    private $schemaName;
    private $extras;

    public function __construct(
        string $id,
        ?string $subscriptionId,
        string $topic,
        string $query,
        string $channel,
        ?array $variables,
        ?string $operationName,
        ?string $schemaName,
        ?array $extras = null
    ) {
        $this->id = $id;
        $this->subscriptionId = $subscriptionId;
        $this->topic = $topic;
        $this->query = $query;
        $this->channel = $channel;
        $this->variables = $variables;
        $this->operationName = $operationName;
        $this->schemaName = $schemaName;
        $this->extras = $extras;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSubscriptionId(): ?string
    {
        return $this->subscriptionId;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getVariables(): ?array
    {
        return $this->variables;
    }

    public function getOperationName(): ?string
    {
        return $this->operationName;
    }

    public function getSchemaName(): ?string
    {
        return $this->schemaName;
    }

    public function getExtras(): ?array
    {
        return $this->extras;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(): string
    {
        return \serialize(\array_filter(\get_object_vars($this)));
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized): void
    {
        $data = \unserialize($serialized);

        foreach ($data as $property => $value) {
            $this->$property = $value;
        }
    }
}
