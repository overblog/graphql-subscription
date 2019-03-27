<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Storage;

use Overblog\GraphQLSubscription\Entity\Subscriber;

final class FilesystemSubscribeStorage implements SubscribeStorageInterface
{
    private $directory;

    private $compress;

    public function __construct(string $directory, int $mask = 0777)
    {
        if (!\file_exists($directory)) {
            @\mkdir($directory, $mask, true);
        }

        $this->directory = $directory;
        $this->compress = \function_exists('gzcompress');
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
            $subscriber->getSchemaName() ? '@'.$subscriber->getSchemaName() : '');
        if ($this->write($fileName, $subscriber)) {
            return true;
        } else {
            throw new \RuntimeException(\sprintf('Failed to write subscription to file "%s".', $fileName));
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

        foreach (\glob($pattern) as $filename) {
            try {
                yield $this->unserialize(\file_get_contents($filename));
            } catch (\Throwable $e) {
                // Ignoring files that could not be unserialized
            }
        }
    }

    private function write(string $file, Subscriber $subscriber): bool
    {
        return false !== \file_put_contents($file, $this->serialize($subscriber));
    }

    private function serialize(Subscriber $subscriber): string
    {
        return $this->compress ? \gzcompress(\serialize($subscriber), 9) : \serialize($subscriber);
    }

    private function unserialize($str): Subscriber
    {
        return $this->compress ? \unserialize(\gzuncompress($str)) : \unserialize($str);
    }
}
