<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Rtmp;

use Kode\Messaging\Exception\RtmpException;

/**
 * RTMP AMF0 编解码
 *
 * AMF0 是 RTMP 命令负载的序列化格式：
 *  - Number  (0x00, IEEE-754 8 字节)
 *  - Boolean (0x01, 1 字节)
 *  - String  (0x02, UTF-8, 2 字节长度前缀)
 *  - Object  (0x03, key/value 列表，0x000009 终止)
 *  - Null    (0x05)
 *  - Array   (0x0A, 4 字节长度 + 元素)
 *  - String (long)  (0x0F, 4 字节长度)
 */
final class Amf0
{
    public const NUMBER = 0x00;
    public const BOOLEAN = 0x01;
    public const STRING = 0x02;
    public const OBJECT = 0x03;
    public const NULL = 0x05;
    public const UNDEFINED = 0x06;
    public const REFERENCE = 0x07;
    public const ECMA_ARRAY = 0x08;
    public const OBJECT_END = 0x09;
    public const STRICT_ARRAY = 0x0A;
    public const DATE = 0x0B;
    public const LONG_STRING = 0x0C;
    public const XML_DOC = 0x0F;
    public const TYPO_OBJ = 0x10;

    public static function encode(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return chr(self::NUMBER).pack('E', (float) $value);
        }
        if (is_bool($value)) {
            return chr(self::BOOLEAN).($value ? "\x01" : "\x00");
        }
        if ($value === null) {
            return chr(self::NULL);
        }
        if (is_string($value)) {
            $len = strlen($value);
            if ($len > 0xFFFF) {
                return chr(self::LONG_STRING).pack('N', $len).$value;
            }

            return chr(self::STRING).pack('n', $len).$value;
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                $buf = chr(self::STRICT_ARRAY).pack('N', count($value));
                foreach ($value as $v) {
                    $buf .= self::encode($v);
                }

                return $buf;
            }
            $buf = chr(self::OBJECT);
            foreach ($value as $k => $v) {
                $ks = (string) $k;
                $buf .= pack('n', strlen($ks)).$ks.self::encode($v);
            }
            $buf .= pack('n', 0).chr(self::OBJECT_END);

            return $buf;
        }

        throw RtmpException::amfError('不支持的 AMF0 类型: '.get_debug_type($value));
    }

    public static function decode(string $buffer, int &$offset = 0): mixed
    {
        if ($offset >= strlen($buffer)) {
            throw RtmpException::amfError('AMF0 缓冲区耗尽');
        }
        $type = ord($buffer[$offset++]);

        return match ($type) {
            self::NUMBER => self::readNumber($buffer, $offset),
            self::BOOLEAN => self::readBoolean($buffer, $offset),
            self::STRING => self::readString($buffer, $offset),
            self::LONG_STRING => self::readLongString($buffer, $offset),
            self::OBJECT => self::readObject($buffer, $offset),
            self::ECMA_ARRAY => self::readObject($buffer, $offset, ecma: true),
            self::STRICT_ARRAY => self::readStrictArray($buffer, $offset),
            self::NULL, self::UNDEFINED => null,
            default => throw RtmpException::amfError('未知 AMF0 类型: 0x'.dechex($type)),
        };
    }

    private static function readNumber(string $b, int &$o): float
    {
        if (strlen($b) < $o + 8) {
            throw RtmpException::amfError('AMF0 Number 截断');
        }
        $v = unpack('E', substr($b, $o, 8))[1];
        $o += 8;

        return (float) $v;
    }

    private static function readBoolean(string $b, int &$o): bool
    {
        if (strlen($b) < $o + 1) {
            throw RtmpException::amfError('AMF0 Boolean 截断');
        }
        $v = ord($b[$o++]) !== 0;

        return $v;
    }

    private static function readString(string $b, int &$o): string
    {
        if (strlen($b) < $o + 2) {
            throw RtmpException::amfError('AMF0 String 长度截断');
        }
        $len = unpack('n', substr($b, $o, 2))[1];
        $o += 2;
        if (strlen($b) < $o + $len) {
            throw RtmpException::amfError('AMF0 String 内容截断');
        }
        $s = substr($b, $o, $len);
        $o += $len;

        return $s;
    }

    private static function readLongString(string $b, int &$o): string
    {
        if (strlen($b) < $o + 4) {
            throw RtmpException::amfError('AMF0 LongString 长度截断');
        }
        $len = unpack('N', substr($b, $o, 4))[1];
        $o += 4;
        if (strlen($b) < $o + $len) {
            throw RtmpException::amfError('AMF0 LongString 内容截断');
        }
        $s = substr($b, $o, $len);
        $o += $len;

        return $s;
    }

    private static function readObject(string $b, int &$o, bool $ecma = false): array
    {
        if ($ecma) {
            // 跳过 4 字节长度
            $o += 4;
        }
        $obj = [];
        while (true) {
            if (strlen($b) < $o + 2) {
                throw RtmpException::amfError('AMF0 Object 键长度截断');
            }
            $klen = unpack('n', substr($b, $o, 2))[1];
            $o += 2;
            if ($klen === 0) {
                if (strlen($b) < $o + 1) {
                    throw RtmpException::amfError('AMF0 Object 终止符截断');
                }
                $endMarker = ord($b[$o++]);
                if ($endMarker !== self::OBJECT_END) {
                    throw RtmpException::amfError('AMF0 Object 终止符非法');
                }
                break;
            }
            if (strlen($b) < $o + $klen) {
                throw RtmpException::amfError('AMF0 Object 键内容截断');
            }
            $key = substr($b, $o, $klen);
            $o += $klen;
            $obj[$key] = self::decode($b, $o);
        }

        return $obj;
    }

    private static function readStrictArray(string $b, int &$o): array
    {
        if (strlen($b) < $o + 4) {
            throw RtmpException::amfError('AMF0 StrictArray 长度截断');
        }
        $count = unpack('N', substr($b, $o, 4))[1];
        $o += 4;
        $arr = [];
        for ($i = 0; $i < $count; $i++) {
            $arr[] = self::decode($b, $o);
        }

        return $arr;
    }
}
