<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Exception\WebSocketException;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket 帧编解码单元测试（边界情况）
 *
 * 覆盖：
 *  - 短载荷（< 126 字节）
 *  - 中等载荷（126 字节，触发 16-bit 长度）
 *  - 大载荷（> 65535 字节，触发 64-bit 长度）
 *  - Close / Ping / Pong 帧编解码
 *  - 分片帧（FIN=0）
 *  - 空载荷
 *  - 异常：帧过短、未 mask
 */
final class WebSocketFrameTest extends TestCase
{
    // ===================== 文本帧 =====================

    public function testTextFrameShortPayload(): void
    {
        $frame = new Frame(true, OpCode::TEXT, 'hello');
        $bytes = $frame->encode();
        $this->assertSame("\x81\x05hello", $bytes);
    }

    public function testTextFrameEmptyPayload(): void
    {
        $frame = new Frame(true, OpCode::TEXT, '');
        $bytes = $frame->encode();
        $this->assertSame("\x81\x00", $bytes);
    }

    public function testTextFrameExtendedPayload126(): void
    {
        // 126 字节载荷 → 触发 16-bit 扩展长度
        $payload = str_repeat('x', 126);
        $frame = new Frame(true, OpCode::TEXT, $payload);
        $bytes = $frame->encode();
        // 0x81 (FIN+TEXT) + 0x7E (126) + 2-byte length + payload
        $this->assertSame(0x81, ord($bytes[0]));
        $this->assertSame(0x7E, ord($bytes[1]));
        $this->assertSame(126, unpack('n', substr($bytes, 2, 2))[1]);
        $this->assertSame($payload, substr($bytes, 4));
    }

    public function testTextFrameExtendedPayload125(): void
    {
        // 125 字节 → 仍在短载荷范围
        $payload = str_repeat('a', 125);
        $frame = new Frame(true, OpCode::TEXT, $payload);
        $bytes = $frame->encode();
        $this->assertSame(0x7D, ord($bytes[1])); // 125 = 0x7D
        $this->assertSame($payload, substr($bytes, 2));
    }

    public function testTextFrameLargePayload65536(): void
    {
        // 65536 字节 → 触发 64-bit 扩展长度
        $payload = str_repeat('b', 65536);
        $frame = new Frame(true, OpCode::TEXT, $payload);
        $bytes = $frame->encode();
        $this->assertSame(0x81, ord($bytes[0]));
        $this->assertSame(0x7F, ord($bytes[1])); // 127 = 0x7F
        $length = unpack('J', substr($bytes, 2, 8))[1];
        $this->assertSame(65536, $length);
        $this->assertSame($payload, substr($bytes, 10));
    }

    // ===================== 控制帧 =====================

    public function testCloseFrame(): void
    {
        $frame = new Frame(true, OpCode::CLOSE, '');
        $bytes = $frame->encode();
        $this->assertSame(0x88, ord($bytes[0])); // FIN + CLOSE
        $this->assertSame(0x00, ord($bytes[1]));
    }

    public function testPingFrame(): void
    {
        $frame = new Frame(true, OpCode::PING, 'ping');
        $bytes = $frame->encode();
        $this->assertSame(0x89, ord($bytes[0])); // FIN + PING
        $this->assertSame(4, ord($bytes[1]));
        $this->assertSame('ping', substr($bytes, 2));
    }

    public function testPongFrame(): void
    {
        $frame = new Frame(true, OpCode::PONG, 'pong');
        $bytes = $frame->encode();
        $this->assertSame(0x8A, ord($bytes[0])); // FIN + PONG
    }

    // ===================== 分片帧 =====================

    public function testFragmentedFrameFinFalse(): void
    {
        $frame = new Frame(false, OpCode::TEXT, 'part1');
        $bytes = $frame->encode();
        // FIN=0 + TEXT → 0x01
        $this->assertSame(0x01, ord($bytes[0]));
        $this->assertSame('part1', substr($bytes, 2));
    }

    public function testContinuationFrame(): void
    {
        $frame = new Frame(false, OpCode::CONTINUATION, 'part2');
        $bytes = $frame->encode();
        // FIN=0 + CONTINUATION → 0x00
        $this->assertSame(0x00, ord($bytes[0]));
    }

    // ===================== Mask =====================

    public function testMaskedFrameHasMaskBit(): void
    {
        $frame = new Frame(true, OpCode::TEXT, 'test');
        $bytes = $frame->encode(masked: true);
        // 第二字节的最高位应该为 1（mask bit）
        $this->assertTrue((ord($bytes[1]) & 0x80) !== 0);
        // mask 占 4 字节，payload 在 mask 之后
        $this->assertSame(4 + 4, strlen($bytes) - 2);
    }

    public function testUnmaskedFrameNoMaskBit(): void
    {
        $frame = new Frame(true, OpCode::TEXT, 'test');
        $bytes = $frame->encode(masked: false);
        $this->assertFalse((ord($bytes[1]) & 0x80) !== 0);
        $this->assertSame(4, strlen($bytes) - 2);
    }

    // ===================== 异常 =====================

    public function testDecodeTooShort(): void
    {
        $this->expectException(WebSocketException::class);
        Frame::decode("\x81", mustMask: false);
    }

    public function testDecodeClientMustMask(): void
    {
        // 服务端发送的帧不应 mask
        $frame = new Frame(true, OpCode::TEXT, 'hello');
        $bytes = $frame->encode(masked: false);
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('客户端帧必须 mask');
        Frame::decode($bytes, mustMask: true);
    }

    public function testDecodeServerShouldNotMask(): void
    {
        $frame = new Frame(true, OpCode::TEXT, 'hello');
        $bytes = $frame->encode(masked: true);
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('服务端帧不应 mask');
        Frame::decode($bytes, mustMask: false);
    }

    // ===================== 往返测试 =====================

    public function testRoundTripUnmasked(): void
    {
        $original = new Frame(true, OpCode::TEXT, 'Hello WebSocket!');
        $bytes = $original->encode(masked: false);
        $decoded = Frame::decode($bytes, mustMask: false);
        $this->assertTrue($decoded->fin);
        $this->assertSame(OpCode::TEXT, $decoded->opcode);
        $this->assertSame('Hello WebSocket!', $decoded->payload);
    }

    public function testRoundTripMasked(): void
    {
        $original = new Frame(true, OpCode::BINARY, "\x01\x02\x03\x04\x05");
        $bytes = $original->encode(masked: true);
        $decoded = Frame::decode($bytes, mustMask: true);
        $this->assertTrue($decoded->fin);
        $this->assertSame(OpCode::BINARY, $decoded->opcode);
        $this->assertSame("\x01\x02\x03\x04\x05", $decoded->payload);
    }

    public function testRoundTripLargePayload(): void
    {
        $payload = str_repeat('Z', 10000);
        $original = new Frame(true, OpCode::TEXT, $payload);
        $bytes = $original->encode(masked: false);
        $decoded = Frame::decode($bytes, mustMask: false);
        $this->assertSame($payload, $decoded->payload);
    }
}
