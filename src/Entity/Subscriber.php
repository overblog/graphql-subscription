<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Entity;

final class Subscriber implements \Serializable
{
    private $id;
    private $topic;
    private $query;
    private $channel;
    private $variables;
    private $operatorName;
    private $schemaName;
    private $extras;

    public function __construct(
        string $id,
        string $topic,
        string $query,
        string $channel,
        ?array $variables,
        ?string $operatorName,
        ?string $schemaName,
        array $extras = []
    ) {
        $this->id = $id;
        $this->topic = $topic;
        $this->query = $query;
        $this->channel = $channel;
        $this->variables = $variables;
        $this->operatorName = $operatorName;
        $this->schemaName = $schemaName;
        $this->extras = $extras;
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getOperatorName(): ?string
    {
        return $this->operatorName;
    }

    public function getSchemaName(): ?string
    {
        return $this->schemaName;
    }

    public function getExtras(): array
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
