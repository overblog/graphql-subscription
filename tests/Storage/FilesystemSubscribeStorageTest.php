<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Tests\Storage;

use Overblog\GraphQLSubscription\Entity\Subscriber;
use Overblog\GraphQLSubscription\Storage\FilesystemSubscribeStorage;
use PHPUnit\Framework\TestCase;

class FilesystemSubscribeStorageTest extends TestCase
{
    private static $directory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = \sys_get_temp_dir().'/overblog-graphql-subscription-'.\time();
    }

    public static function tearDownAfterClass(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                \rmdir($file->getRealPath());
            } else {
                \unlink($file->getRealPath());
            }
        }
        \rmdir(self::$directory);
    }

    public function testDirectoryInitialisation(): FilesystemSubscribeStorage
    {
        $this->assertDirectoryNotExists(self::$directory);
        $storage = new FilesystemSubscribeStorage(self::$directory);
        $this->assertDirectoryExists(self::$directory);

        return $storage;
    }

    /**
     * @depends testDirectoryInitialisation
     *
     * @param FilesystemSubscribeStorage $storage
     *
     * @return FilesystemSubscribeStorage
     */
    public function testStore(FilesystemSubscribeStorage $storage): FilesystemSubscribeStorage
    {
        $storage->store(
            new Subscriber(...\array_values($this->subscriber1Args()))
        );
        $this->assertFileExists(self::$directory.'/my-unique-id--channel@main');

        $storage->store(
            new Subscriber(...\array_values($this->subscriber2Args()))
        );

        $this->assertFileExists(self::$directory.'/my-unique-id-2--channel2');

        return $storage;
    }

    /**
     * @depends testStore
     *
     * @param FilesystemSubscribeStorage $storage
     */
    public function testFind(FilesystemSubscribeStorage $storage): void
    {
        $subscribers = $storage->findSubscribersByChannelAndSchemaName('channel', 'main');
        $subscriber = $subscribers->current();
        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertSubscriberProperties($this->subscriber1Args(), $subscriber);

        $subscribers = $storage->findSubscribersByChannelAndSchemaName('channel2', null);
        $subscriber = $subscribers->current();
        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertSubscriberProperties($this->subscriber2Args(), $subscriber);
    }

    /**
     * @depends testStore
     *
     * @param FilesystemSubscribeStorage $storage
     */
    public function testDelete(FilesystemSubscribeStorage $storage): void
    {
        $storage->delete('my-unique-id');
        /** @var \Generator $subscribers */
        $subscribers = $storage->findSubscribersByChannelAndSchemaName('channel', 'main');
        $this->assertEmpty(\iterator_to_array($subscribers));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Subscriber with id "my-unique-id" could not be found.');
        $storage->delete('my-unique-id');
    }

    private function assertSubscriberProperties(array $expectedProperties, Subscriber $subscriber): void
    {
        foreach ($expectedProperties as $name => $value) {
            $method = 'get'.\ucfirst($name);
            $this->assertSame($value, $subscriber->$method());
        }
    }

    private static function subscriber1Args(): array
    {
        return [
            'id' => 'my-unique-id',
            'topic' => 'http://mytopic.org/unique-id-1',
            'query' => 'baz {q}',
            'channel' => 'channel',
            'variables' => ['foo' => 'bar'],
            'operationName' => 'baz',
            'schemaName' => 'main',
        ];
    }

    private static function subscriber2Args(): array
    {
        return [
            'id' => 'my-unique-id-2',
            'topic' => 'http://mytopic.org/unique-id-2',
            'query' => '{q}',
            'channel' => 'channel2',
            'variables' => null,
            'operationName' => null,
            'schemaName' => null,
        ];
    }
}
