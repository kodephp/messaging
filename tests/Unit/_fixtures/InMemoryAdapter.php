<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit\_fixtures;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Contract\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * 测试用：可计数、可观测的内存适配器
 *
 * 模拟一个最小可用的客户端适配器：connect() 返回内存连接，
 * subscribe() 返回 sid=1，publish() 自增计数。
 *
 * 不被 PHPUnit 自动加载（位于 _fixtures 目录），但通过 PSR-4 autoload
 * 仍可被显式 use。
 */
final class InMemoryAdapter extends AbstractAdapter
{
    public static int $connectCount = 0;

    public static int $lastPublishCount = 0;

    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct($logger);
    }

    public static function scheme(): string
    {
        return 'test-bld';
    }

    public function version(): string
    {
        return 'test-1.0';
    }

    public function listen(string $host, int $port): void {}

    public function connect(array $config): ConnectionInterface
    {
        self::$connectCount++;

        return new FakeConnection(
            connId: 'test-'.self::$connectCount,
            protocol: 'test-bld',
            remoteAddress: '127.0.0.1:0',
        );
    }

    public function run(): void {}

    public function subscribe(string $topic, callable $handler, mixed $extra = null): mixed
    {
        return 1;
    }

    public function publish(string $topic, string $payload, mixed $extra = null): void
    {
        self::$lastPublishCount++;
    }
}
