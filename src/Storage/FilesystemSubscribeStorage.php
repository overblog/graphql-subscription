<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Storage;

use Overblog\GraphQLSubscription\Entity\Subscriber;

final class FilesystemSubscribeStorage implements SubscribeStorageInterface
{
    private $directory;

    public function __construct(string $directory, int $mask = 0777)
    {
        if (!\is_dir($directory)) {
            \mkdir($directory, $mask, true);
        }

        $this->directory = $directory;
    }

    /**
     * {@inheritdoc}
     */
    public function store(Subscriber $subscriber): bool
    {
        $fileName = \sprintf(
            '%s/%s--%s%s',
            $this->directory,
            $subscriber->getId(),
            $subscriber->getChannel(),
            $subscriber->getSchemaName() ? '@'.$subscriber->getSchemaName() : ''
        );
        if ($this->write($fileName, $subscriber)) {
            return true;
        } else {
            throw new \RuntimeException(\sprintf('Failed to write subscriber to file "%s".', $fileName));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findSubscribersByChannelAndSchemaName(string $channel, ?string $schemaName): iterable
    {
        $pattern = \sprintf(
            '%s/*--%s%s',
            $this->directory,
            $channel,
            $schemaName ? '@'.$schemaName : ''
        );

        foreach (\glob($pattern) ?: [] as $filename) {
            try {
                yield $this->unserialize(\file_get_contents($filename));
            } catch (\Throwable $e) {
                // Ignoring files that could not be unserialized
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $subscriberID): bool
    {
        $fileName = $this->findOneByID($subscriberID);
        if (null === $fileName) {
            throw new \InvalidArgumentException(\sprintf(
                'Subscriber with id "%s" could not be found.',
                $subscriberID
            ));
        }

        return @\unlink($fileName);
    }

    private function findOneByID(string $subscriberID): ?string
    {
        $pattern = \sprintf(
            '%s/%s--*',
            $this->directory,
            $subscriberID
        );
        $files = \glob($pattern);

        return empty($files) ? null : $files[0];
    }

    private function write(string $file, Subscriber $subscriber): bool
    {
        return false !== \file_put_contents($file, $this->serialize($subscriber));
    }

    private function serialize(Subscriber $subscriber): string
    {
        return \serialize($subscriber);
    }

    private function unserialize($str): Subscriber
    {
        return \unserialize($str);
    }
}
