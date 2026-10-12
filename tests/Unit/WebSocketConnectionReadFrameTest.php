<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;
use Kode\Messaging\Exception\WebSocketException;
use PHPUnit\Framework\TestCase;

/**
 * readFrame() 流式读取安全防线（RFC 6455）
 *
 * 覆盖：
 *  - 正常掩码帧往返（回归）
 *  - 分配前上限守卫：超大声明长度 → 1009 关闭，绝不按声明长度分配
 *  - 掩码方向强制：服务端要求客户端帧 mask / 客户端拒绝服务端帧 mask
 *  - 保留 opcode 拒绝（0x3-0x7、0xB-0xF → 1002）
 *  - 64 位长度最高位（负长度 → 1002）
 *  - 单帧读取 deadline：慢速滴灌 → 1001 关闭而非无限阻塞
 *  - 对端 EOF：直接返回 null（由调用方按 eof 回收连接）
 */
final class WebSocketConnectionReadFrameTest extends TestCase
{
    /** @var array<int, resource> */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            if (is_resource($peer)) {
                @fclose($peer);
            }
        }
        $this->peers = [];
        parent::tearDown();
    }

    /**
     * @return array{0: WebSocketConnection, 1: resource} [连接, 对端流]
     */
    private function pair(int $maxFrameSize = 1_048_576, float $readTimeout = 10.0): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$a, $b] = $pair;
        $conn = new WebSocketConnection(
            'c-test',
            'ws',
            'peer',
            $a,
            maxFrameSize: $maxFrameSize,
            readTimeout: $readTimeout,
        );
        $this->peers[] = $b;

        return [$conn, $b];
    }

    /** 从对端读出 conn.close() 发来的关闭帧，返回 [code, reason] */
    private function readCloseFrame($peer): array
    {
        $data = '';
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($peer, 8192);
            if ($chunk !== false && $chunk !== '') {
                $data .= $chunk;
            }
            if ($data !== '') {
                try {
                    $frame = Frame::decode($data, mustMask: false);

                    return [unpack('n', substr($frame->payload, 0, 2))[1], substr($frame->payload, 2)];
                } catch (\Kode\Messaging\Exception\WebSocketException) {
                    // 半包，继续读
                }
            }
            usleep(1_000);
        }
        $this->fail('对端应收到可解码的关闭帧，实际: ' . bin2hex($data));
    }

    public function testNormalMaskedTextFrameRoundTrip(): void
    {
        [$conn, $peer] = $this->pair();
        fwrite($peer, Frame::text('hello')->encode(masked: true));
        $frame = $conn->readFrame(mustMask: true);

        $this->assertNotNull($frame);
        $this->assertSame('hello', $frame->payload);
        $this->assertSame(OpCode::TEXT, $frame->opcode);
        $this->assertTrue($conn->isOpen());
    }

    public function testServerRejectsUnmaskedClientFrame(): void
    {
        [$conn, $peer] = $this->pair();
        fwrite($peer, Frame::text('no mask')->encode(masked: false));
        $frame = $conn->readFrame(mustMask: true);

        $this->assertNull($frame);
        $this->assertFalse($conn->isOpen(), '未掩码的客户端帧必须断开');
        [$code] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_PROTOCOL_ERROR, $code);
    }

    public function testClientRejectsMaskedServerFrame(): void
    {
        [$conn, $peer] = $this->pair();
        fwrite($peer, Frame::text('masked server frame')->encode(masked: true));
        $frame = $conn->readFrame(mustMask: false);

        $this->assertNull($frame);
        $this->assertFalse($conn->isOpen());
        [$code] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_PROTOCOL_ERROR, $code);
    }

    public function testOversizedDeclaredLengthClosesWith1009BeforeAllocation(): void
    {
        // 上限压到 64 字节，对端声明 2000 字节（16 位长度字段，不发真实载荷）
        [$conn, $peer] = $this->pair(maxFrameSize: 64);
        $header = "\x82\x7E" . pack('n', 2000);
        fwrite($peer, $header);
        $frame = $conn->readFrame(mustMask: false);

        $this->assertNull($frame, '超大帧不应被读入');
        $this->assertFalse($conn->isOpen());
        [$code] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_MESSAGE_TOO_BIG, $code);
    }

    public function testReservedOpcodeRejected(): void
    {
        // 0x3 为保留 opcode；掩码合法，仅 opcode 非法
        [$conn, $peer] = $this->pair();
        $raw = "\x83" . "\x85" . "\x01\x02\x03\x04" . Frame::applyMask('hello', "\x01\x02\x03\x04");
        fwrite($peer, $raw);
        $frame = $conn->readFrame(mustMask: true);

        $this->assertNull($frame);
        $this->assertFalse($conn->isOpen());
        [$code, $reason] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_PROTOCOL_ERROR, $code);
        $this->assertStringContainsString('opcode', $reason);
    }

    public function testNegative64BitLengthRejected(): void
    {
        [$conn, $peer] = $this->pair();
        fwrite($peer, "\x81\x7F" . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF");
        $frame = $conn->readFrame(mustMask: false);

        $this->assertNull($frame);
        $this->assertFalse($conn->isOpen());
        [$code] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_PROTOCOL_ERROR, $code);
    }

    public function testSlowDripFrameHitsReadDeadline(): void
    {
        // 只发帧头与掩码，载荷 5 字节只给 1 字节 → 50ms 内必须以 1001 收场
        [$conn, $peer] = $this->pair(readTimeout: 0.05);
        $header = "\x81\x85" . "\x01\x02\x03\x04" . 'x';
        fwrite($peer, $header);

        $start = microtime(true);
        $frame = $conn->readFrame(mustMask: true);
        $elapsed = microtime(true) - $start;

        $this->assertNull($frame);
        $this->assertFalse($conn->isOpen(), '慢速滴灌帧必须被关闭');
        $this->assertLessThan(2.0, $elapsed, '不应阻塞到流级超时（默认 60s）');
        [$code, $reason] = $this->readCloseFrame($peer);
        $this->assertSame(WebSocketException::CODE_GOING_AWAY, $code);
        $this->assertStringContainsString('timeout', $reason);
    }

    public function testPeerEofReturnsNullWithoutClose(): void
    {
        [$conn, $peer] = $this->pair();
        fclose($peer);
        array_pop($this->peers); // 已手动关闭

        $frame = $conn->readFrame(mustMask: true);

        $this->assertNull($frame);
        // EOF 时对端已不可达，无需再写关闭帧；连接标记保留给调用方按 eof 回收
        $this->assertTrue($conn->isOpen());
    }
}
