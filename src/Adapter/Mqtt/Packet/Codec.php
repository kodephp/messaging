<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

use InvalidArgumentException;
use Kode\Messaging\Exception\MqttException;

/**
 * MQTT 协议包编解码（3.1.1 / 5.0）
 *
 * 核心：剩余长度（Remaining Length）使用变长整数编码。
 * 参考：MQTT 3.1.1 §2.1 / MQTT 5.0 §1.5.5
 */
final class Codec
{
    /**
     * 编码一个固定头。
     *
     * @return string 字节流
     */
    public static function encodeFixedHeader(int $type, int $flags, int $remainingLength): string
    {
        $byte0 = (($type & 0x0F) << 4) | ($flags & 0x0F);

        return chr($byte0).self::encodeRemainingLength($remainingLength);
    }

    /**
     * 编码剩余长度（变长整数）。
     */
    public static function encodeRemainingLength(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Remaining length cannot be negative');
        }
        $output = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $output .= chr($byte);
        } while ($length > 0);

        return $output;
    }

    /**
     * 解码剩余长度。
     */
    public static function decodeRemainingLength(string $data, int &$offset): int
    {
        $multiplier = 1;
        $value = 0;
        $start = $offset;
        do {
            if ($offset >= strlen($data)) {
                throw MqttException::malformedPacket('Remaining length 截断');
            }
            $byte = ord($data[$offset++]);
            $value += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            if ($multiplier > 128 * 128 * 128) {
                throw MqttException::malformedPacket('Remaining length 非法');
            }
        } while (($byte & 0x80) !== 0);

        return $value;
    }

    /**
     * 编码 UTF-8 字符串。
     */
    public static function encodeString(string $s): string
    {
        $bytes = $s;

        return pack('n', strlen($bytes)).$bytes;
    }

    /**
     * 解码 UTF-8 字符串。
     */
    public static function decodeString(string $data, int &$offset): string
    {
        if (strlen($data) < $offset + 2) {
            throw MqttException::malformedPacket('String 长度缺失');
        }
        $len = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        if (strlen($data) < $offset + $len) {
            throw MqttException::malformedPacket('String 内容截断');
        }
        $str = substr($data, $offset, $len);
        $offset += $len;

        return $str;
    }

    /**
     * 编码 16-bit 无符号整数。
     */
    public static function encodeUint16(int $v): string
    {
        return pack('n', $v & 0xFFFF);
    }

    /**
     * 解码 16-bit 无符号整数。
     */
    public static function decodeUint16(string $data, int &$offset): int
    {
        $v = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        return $v;
    }

    /**
     * 编码 8-bit 无符号整数。
     */
    public static function encodeUint8(int $v): string
    {
        return chr($v & 0xFF);
    }

    public static function decodeUint8(string $data, int &$offset): int
    {
        $v = ord($data[$offset]);
        ++$offset;

        return $v;
    }

    /**
     * 编码二进制数据（含 16-bit 长度前缀）。
     */
    public static function encodeBinary(string $data): string
    {
        return pack('n', strlen($data)).$data;
    }

    public static function decodeBinary(string $data, int &$offset): string
    {
        return self::decodeString($data, $offset);
    }
}
