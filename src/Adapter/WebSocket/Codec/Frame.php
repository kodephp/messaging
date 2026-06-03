<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket\Codec;

use Kode\Messaging\Exception\WebSocketException;

/**
 * WebSocket 帧（RFC 6455 §5）
 *
 * 不可变对象。Frame::decode() 从字节流解码一帧；
 * Frame::encode() 序列化为发送字节流。
 */
final class Frame
{
    public function __construct(
        public readonly bool $fin,
        public readonly int $opcode,
        public readonly string $payload,
        public readonly bool $masked = false,
    ) {
    }

    /**
     * 将 payload 编码为 WebSocket 帧字节流。
     *
     * 注意：服务端发往客户端的帧 **不** 应 mask（RFC 6455 §5.1）。
     */
    public function encode(bool $masked = false): string
    {
        $firstByte = ($this->fin ? 0x80 : 0x00) | ($this->opcode & 0x0F);
        $payload = $this->payload;
        $len = strlen($payload);
        $secondByte = $masked ? 0x80 : 0x00;
        $header = chr($firstByte);

        if ($len < 126) {
            $header .= chr($secondByte | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr($secondByte | 126) . pack('n', $len);
        } else {
            $header .= chr($secondByte | 127) . pack('J', $len);
        }

        if ($masked) {
            $mask = random_bytes(4);
            $maskedPayload = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
            $maskedPayload = substr($maskedPayload, 0, $len);
            return $header . $mask . $maskedPayload;
        }

        return $header . $payload;
    }

    /**
     * 从字节流中解码一帧（不处理跨帧合并）。
     */
    public static function decode(string $data, bool $mustMask = true): self
    {
        if (strlen($data) < 2) {
            throw WebSocketException::invalidFrame('帧过短', ['len' => strlen($data)]);
        }

        $first = ord($data[0]);
        $second = ord($data[1]);
        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;

        if ($mustMask && !$masked) {
            throw WebSocketException::invalidFrame('客户端帧必须 mask', []);
        }
        if (!$mustMask && $masked) {
            throw WebSocketException::invalidFrame('服务端帧不应 mask', []);
        }

        $len = $second & 0x7F;
        $offset = 2;
        if ($len === 126) {
            if (strlen($data) < 4) {
                throw WebSocketException::invalidFrame('16 位长度字段缺失', []);
            }
            $len = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($data) < 10) {
                throw WebSocketException::invalidFrame('64 位长度字段缺失', []);
            }
            $len = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        $mask = '';
        if ($masked) {
            if (strlen($data) < $offset + 4) {
                throw WebSocketException::invalidFrame('Mask 字段缺失', []);
            }
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        if (strlen($data) < $offset + $len) {
            throw WebSocketException::invalidFrame('Payload 不足', ['expected' => $len, 'available' => strlen($data) - $offset]);
        }

        $payload = substr($data, $offset, $len);
        if ($masked) {
            $payload = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
            $payload = substr($payload, 0, $len);
        }

        return new self($fin, $opcode, $payload, $masked);
    }

    public function isControl(): bool
    {
        return $this->opcode >= 0x8;
    }

    public static function text(string $payload, bool $fin = true): self
    {
        return new self($fin, OpCode::TEXT, $payload);
    }

    public static function binary(string $payload, bool $fin = true): self
    {
        return new self($fin, OpCode::BINARY, $payload);
    }

    public static function close(int $code = 1000, string $reason = ''): self
    {
        $payload = pack('n', $code) . $reason;
        return new self(true, OpCode::CLOSE, $payload);
    }

    public static function ping(string $payload = ''): self
    {
        return new self(true, OpCode::PING, $payload);
    }

    public static function pong(string $payload = ''): self
    {
        return new self(true, OpCode::PONG, $payload);
    }
}
