<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\LongPolling\LongPollingConnection;
use Kode\Messaging\Exception\LongPollingException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * LongPolling 单元测试（部分）
 *
 * 注：完整 Server 集成测试需要真实端口，参见 tests/Integration/。
 */
final class LongPollingTest extends TestCase
{
    public function test_request_invalid_exception(): void
    {
        $ex = LongPollingException::requestInvalid('bad method', ['method' => 'INVALID']);
        $this->assertSame(6002, $ex->getCode());
        $this->assertStringContainsString('bad method', $ex->getMessage());
        $this->assertSame(['method' => 'INVALID'], $ex->context());
    }

    public function test_hold_timeout_exception(): void
    {
        $ex = LongPollingException::holdTimeout(25_000);
        $this->assertSame(6004, $ex->getCode());
        $this->assertStringContainsString('25000', $ex->getMessage());
    }

    public function test_queue_overflow_exception(): void
    {
        $ex = LongPollingException::queueOverflow('orders', 1000, 500);
        $this->assertSame(6005, $ex->getCode());
        $this->assertStringContainsString('orders', $ex->getMessage());
    }

    public function test_connection_encode_payload(): void
    {
        // 验证 LongPollingConnection 的 payload 编码逻辑（通过 reflection）
        $conn = new LongPollingConnection('lp-test', '127.0.0.1:1234', null, []);

        $ref = new ReflectionClass($conn);
        $encode = $ref->getMethod('encode');
        $encode->setAccessible(true);

        $this->assertSame('hello', $encode->invoke($conn, 'hello', []));
        $this->assertSame('{"a":1}', $encode->invoke($conn, ['a' => 1], []));
        $this->assertSame('{"a":1}', $encode->invoke($conn, ['a' => 1], ['throw_on_error' => true]));
        $this->assertSame('', $encode->invoke($conn, null, []));
        $this->assertSame('123', $encode->invoke($conn, 123, []));
    }
}
