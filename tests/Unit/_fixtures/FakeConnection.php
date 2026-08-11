<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit\_fixtures;

use Kode\Messaging\Connection\Connection;

/**
 * 测试用：可观测的连接
 *
 * send() 返回 true（默认 Connection 返回 false），用于测试 Builder 路径。
 */
final class FakeConnection extends Connection
{
    public static int $sendCount = 0;

    public function send(mixed $payload, array $options = []): bool
    {
        self::$sendCount++;

        return true;
    }
}
