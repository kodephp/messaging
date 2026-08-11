<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit\_fixtures;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Contract\ConnectionInterface;
use RuntimeException;

/**
 * 测试用：connect() 抛异常的适配器
 *
 * 用于测试 Builder 在 connect() 失败时把异常透传给调用方的行为。
 */
final class FailingAdapter extends AbstractAdapter
{
    public static function scheme(): string
    {
        return 'failing-adapter';
    }

    public function version(): string
    {
        return 'test-1.0';
    }

    public function listen(string $host, int $port): void {}

    public function run(): void {}

    public function connect(array $config): ConnectionInterface
    {
        throw new RuntimeException('mock connect failure');
    }
}
