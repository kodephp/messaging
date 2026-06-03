<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Exception\CoapException;
use PHPUnit\Framework\TestCase;

final class CoapExceptionTest extends TestCase
{
    public function testBindFailed(): void
    {
        $ex = CoapException::bindFailed('127.0.0.1', 5683, 'permission denied');
        $this->assertSame(7001, $ex->getCode());
        $this->assertStringContainsString('127.0.0.1:5683', $ex->getMessage());
        $this->assertSame('permission denied', $ex->context()['reason']);
    }

    public function testFromResponseCode(): void
    {
        $client = CoapException::fromResponseCode(4.04, 'Not Found');
        $this->assertSame(404, $client->getCode());
        $this->assertStringContainsString('客户端错误', $client->getMessage());
        $this->assertStringContainsString('4.04', $client->getMessage());

        $server = CoapException::fromResponseCode(5.00);
        $this->assertSame(500, $server->getCode());
        $this->assertStringContainsString('服务端错误', $server->getMessage());
    }

    public function testTokenMismatch(): void
    {
        $ex = CoapException::tokenMismatch(2, 4);
        $this->assertSame(7004, $ex->getCode());
        $this->assertSame(2, $ex->context()['expected']);
        $this->assertSame(4, $ex->context()['actual']);
    }

    public function testRetransmitExhausted(): void
    {
        $ex = CoapException::retransmitExhausted(4, 2_000);
        $this->assertSame(7006, $ex->getCode());
        $this->assertSame(4, $ex->context()['retries']);
        $this->assertSame(2_000, $ex->context()['timeout_ms']);
    }

    public function testPacketParseFailed(): void
    {
        $ex = CoapException::packetParseFailed('invalid header', ['offset' => 4]);
        $this->assertSame(7002, $ex->getCode());
        $this->assertStringContainsString('invalid header', $ex->getMessage());
    }
}
