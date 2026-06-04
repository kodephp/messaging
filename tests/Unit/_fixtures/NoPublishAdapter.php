<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit\_fixtures;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Connection\Connection;
use Kode\Messaging\Contract\ConnectionInterface;

/**
 * 测试用：支持 connect 但不实现 publish() 的适配器
 */
final class NoPublishAdapter extends AbstractAdapter
{
    public static function scheme(): string
    {
        return 'no-publish';
    }

    public function version(): string
    {
        return 'test-1.0';
    }

    public function listen(string $host, int $port): void
    {
    }

    public function run(): void
    {
    }

    public function connect(array $config): ConnectionInterface
    {
        return new Connection(
            connId: 'np-1',
            protocol: 'no-publish',
            remoteAddress: '127.0.0.1:0',
        );
    }
    // 故意不实现 publish()
}
