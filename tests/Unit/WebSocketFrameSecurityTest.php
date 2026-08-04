<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Exception\WebSocketException;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket 帧掩码与解码安全约束单元测试
 *
 * 覆盖：
 *  - applyMask() 对称性与长度不变性（含非 4 字节对齐、空载荷）
 *  - 控制帧不可分片、载荷 ≤ 125（RFC 6455 §5.5）
 *  - close() 超长 reason 自动截断，保证生成的控制帧合法
 *  - maxPayload 上限保护（默认 null 不限制，保持向后兼容）
 *  - 64 位长度最高位必须为 0
 */
final class WebSocketFrameSecurityTest extends TestCase
{
    public function testApplyMaskIsSymmetricAndLengthPreserving(): void
    {
        $mask = "\x01\x02\x03\x04";
        foreach (['', 'a', 'abc', 'abcd', 'abcde', str_repeat('x', 1000)] as $payload) {
            $masked = Frame::applyMask($payload, $mask);
            $this->assertSame(strlen($payload), strlen($masked));
            $this->assertSame($payload, Frame::applyMask($masked, $mask));
        }
    }

    public function testApplyMaskWithEmptyMaskIsNoop(): void
    {
        $this->assertSame('abc', Frame::applyMask('abc', ''));
    }

    public function testMaskedRoundTripUnalignedLength(): void
    {
        // 7 字节：非 4 字节对齐，验证掩码流精确截断
        $frame = new Frame(true, OpCode::BINARY, "\x01\x02\x03\x04\x05\x06\x07");
        $decoded = Frame::decode($frame->encode(masked: true), mustMask: true);
        $this->assertSame("\x01\x02\x03\x04\x05\x06\x07", $decoded->payload);
    }

    public function testFragmentedControlFrameRejected(): void
    {
        // FIN=0 + PING(0x9) → 0x09，载荷 0 字节，未 mask
        $bytes = "\x09\x00";
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('控制帧不可分片');
        Frame::decode($bytes, mustMask: false);
    }

    public function testOversizedControlFrameRejected(): void
    {
        // FIN + PING，声明 126 字节载荷（>125）
        $payload = str_repeat('p', 126);
        $bytes = "\x89\x7E" . pack('n', 126) . $payload;
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('控制帧载荷超过 125 字节');
        Frame::decode($bytes, mustMask: false);
    }

    public function testCloseFrameTruncatesLongReason(): void
    {
        $frame = Frame::close(1000, str_repeat('r', 500));
        $this->assertSame(Frame::MAX_CONTROL_PAYLOAD, strlen($frame->payload));
        // 截断后仍是合法控制帧，可被解码
        $decoded = Frame::decode($frame->encode(masked: false), mustMask: false);
        $this->assertSame(OpCode::CLOSE, $decoded->opcode);
    }

    public function testMaxPayloadGuardRejectsOversizedFrame(): void
    {
        $frame = new Frame(true, OpCode::TEXT, str_repeat('a', 1000));
        $bytes = $frame->encode(masked: false);

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('帧载荷超过上限');
        Frame::decode($bytes, mustMask: false, maxPayload: 512);
    }

    public function testMaxPayloadGuardAllowsWithinLimit(): void
    {
        $frame = new Frame(true, OpCode::TEXT, str_repeat('a', 1000));
        $decoded = Frame::decode($frame->encode(masked: false), mustMask: false, maxPayload: 1024);
        $this->assertSame(1000, strlen($decoded->payload));
    }

    public function testDefaultDecodeHasNoPayloadLimit(): void
    {
        $frame = new Frame(true, OpCode::TEXT, str_repeat('a', 70000));
        $decoded = Frame::decode($frame->encode(masked: false), mustMask: false);
        $this->assertSame(70000, strlen($decoded->payload));
    }

    public function testNegative64BitLengthRejected(): void
    {
        // 64 位长度最高位为 1 → PHP 下解析为负数，必须拒绝
        $bytes = "\x81\x7F" . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('64 位长度非法');
        Frame::decode($bytes, mustMask: false);
    }
}
