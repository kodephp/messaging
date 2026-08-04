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
    /** 控制帧（close/ping/pong）载荷上限（RFC 6455 §5.5） */
    public const MAX_CONTROL_PAYLOAD = 125;

    public function __construct(
        public readonly bool $fin,
        public readonly int $opcode,
        public readonly string $payload,
        public readonly bool $masked = false,
    ) {
    }

    /**
     * 应用 4 字节掩码（RFC 6455 §5.3）。
     *
     * 掩码是对称运算，编码与解码共用同一实现。
     * str_pad 一次性生成与 payload 等长的掩码流；
     * 原实现 str_repeat 过量分配后再 substr 截断，会多出一整份载荷拷贝
     * （大帧场景下峰值内存约为载荷的 3 倍，现降为 2 倍）。
     */
    public static function applyMask(string $payload, string $mask): string
    {
        if ($payload === '' || $mask === '') {
            return $payload;
        }
        return $payload ^ str_pad('', strlen($payload), $mask);
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
            return $header . $mask . self::applyMask($payload, $mask);
        }

        return $header . $payload;
    }

    /**
     * 从字节流中解码一帧（不处理跨帧合并）。
     *
     * @param int|null $maxPayload 载荷上限（字节）；超过则抛异常。
     *                             null = 不限制（保持向后兼容），生产环境建议显式传入以防内存耗尽攻击。
     */
    public static function decode(string $data, bool $mustMask = true, ?int $maxPayload = null): self
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
            // RFC 6455 §5.2：64 位长度最高有效位必须为 0；PHP 下溢出会变成负数
            if ($len < 0) {
                throw WebSocketException::invalidFrame('64 位长度非法（最高位必须为 0）', []);
            }
            $offset = 10;
        }

        // 控制帧约束（RFC 6455 §5.5）：不可分片，且载荷 ≤ 125 字节
        if ($opcode >= 0x8) {
            if (!$fin) {
                throw WebSocketException::invalidFrame('控制帧不可分片', ['opcode' => $opcode]);
            }
            if ($len > self::MAX_CONTROL_PAYLOAD) {
                throw WebSocketException::invalidFrame('控制帧载荷超过 125 字节', ['len' => $len]);
            }
        }

        // 载荷上限保护：在分配内存之前拒绝超大帧
        if ($maxPayload !== null && $len > $maxPayload) {
            throw WebSocketException::invalidFrame('帧载荷超过上限', ['len' => $len, 'max' => $maxPayload]);
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
            $payload = self::applyMask($payload, $mask);
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
        // 控制帧载荷上限 125 字节，其中 2 字节为状态码；超长 reason 截断，避免生成非法帧
        if (strlen($reason) > self::MAX_CONTROL_PAYLOAD - 2) {
            $reason = substr($reason, 0, self::MAX_CONTROL_PAYLOAD - 2);
        }
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
