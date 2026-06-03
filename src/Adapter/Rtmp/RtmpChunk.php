<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Rtmp;

use Kode\Messaging\Exception\RtmpException;

/**
 * RTMP Chunk 编解码
 *
 * RTMP Message 通过 Chunk 流发送，每个 Chunk = Basic Header + Message Header + Extended Timestamp + Chunk Data
 *
 * Basic Header（1-3 字节）：
 *   高 2 位：fmt（chunk type, 0-3）
 *   低 6 位：chunk stream id（csid）
 *   - 0：1 字节，csid = 64 + 下一字节
 *   - 1：2 字节，csid = 64 + 下一字节
 *   - 2：保留（协议保留给低层控制）
 *   - 3-63：csid 自身
 *
 * Message Header（0/3/7/11 字节，取决于 fmt）：
 *   fmt=0: timestamp(3) + message_length(3) + message_type(1) + message_stream_id(4)
 *   fmt=1: timestamp_delta(3) + message_length(3) + message_type(1)
 *   fmt=2: timestamp_delta(3)
 *   fmt=3: 无
 *
 * Extended Timestamp（0/4 字节）：当 fmt=0/1/2 且 timestamp >= 0xFFFFFF
 */
final class RtmpChunk
{
    public const CHUNK_SIZE_DEFAULT = 128;
    public const CHUNK_SIZE_MAX     = 65536;

    public const FMT_FULL         = 0;
    public const FMT_SAME_STREAM  = 1;
    public const FMT_SAME_LENGTH  = 2;
    public const FMT_CONTINUATION = 3;

    /**
     * 编码 chunk basic header。
     */
    public static function encodeBasicHeader(int $fmt, int $csid): string
    {
        $fmt = $fmt & 0x03;
        if ($csid < 64) {
            return chr(($fmt << 6) | $csid);
        }
        if ($csid < 320) {
            return chr(($fmt << 6) | 0) . chr($csid - 64);
        }
        return chr(($fmt << 6) | 1) . pack('n', $csid - 64);
    }

    /**
     * 解码 chunk basic header。
     *
     * @return array{fmt:int, csid:int, consumed:int}|null
     */
    public static function decodeBasicHeader(string $buffer, int $offset = 0): ?array
    {
        if (strlen($buffer) < $offset + 1) {
            return null;
        }
        $b = ord($buffer[$offset]);
        $fmt = ($b >> 6) & 0x03;
        $csid = $b & 0x3F;
        if ($csid === 0) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }
            $csid = 64 + ord($buffer[$offset + 1]);
            return ['fmt' => $fmt, 'csid' => $csid, 'consumed' => 2];
        }
        if ($csid === 1) {
            if (strlen($buffer) < $offset + 3) {
                return null;
            }
            $csid = 64 + unpack('n', substr($buffer, $offset + 1, 2))[1];
            return ['fmt' => $fmt, 'csid' => $csid, 'consumed' => 3];
        }
        return ['fmt' => $fmt, 'csid' => $csid, 'consumed' => 1];
    }

    /**
     * 编码 message header。
     *
     * 包含 Extended Timestamp（如果需要）。
     */
    public static function encodeMessageHeader(
        int $fmt,
        int $timestamp,
        int $messageLength,
        int $messageType,
        int $messageStreamId,
    ): string {
        $useExt = $timestamp >= 0xFFFFFF;
        $ts = $useExt ? 0xFFFFFF : ($timestamp & 0xFFFFFF);
        $buf = '';
        if ($fmt === self::FMT_FULL) {
            $buf .= self::encodeUint24($ts);
            $buf .= self::encodeUint24($messageLength);
            $buf .= chr($messageType & 0xFF);
            $buf .= pack('N', $messageStreamId & 0xFFFFFFFF);
        } elseif ($fmt === self::FMT_SAME_STREAM) {
            $buf .= self::encodeUint24($ts);
            $buf .= self::encodeUint24($messageLength);
            $buf .= chr($messageType & 0xFF);
        } elseif ($fmt === self::FMT_SAME_LENGTH) {
            $buf .= self::encodeUint24($ts);
        }
        if ($useExt) {
            $buf .= pack('N', $timestamp & 0xFFFFFFFF);
        }
        return $buf;
    }

    /**
     * 解码 message header（需要外部维持 fmt 上下文）。
     *
     * @return array{timestamp:int, messageLength:int, messageType:int, messageStreamId:int, consumed:int}|null
     */
    public static function decodeMessageHeader(int $fmt, string $buffer, int $offset = 0): ?array
    {
        $size = match (true) {
            $fmt === self::FMT_FULL         => 11,
            $fmt === self::FMT_SAME_STREAM  => 7,
            $fmt === self::FMT_SAME_LENGTH  => 3,
            $fmt === self::FMT_CONTINUATION => 0,
            default => throw RtmpException::chunkError('未知 fmt: ' . $fmt),
        };
        if (strlen($buffer) < $offset + $size) {
            return null;
        }
        $p = $offset;
        $timestamp = 0;
        $messageLength = 0;
        $messageType = 0;
        $messageStreamId = 0;
        if ($fmt === self::FMT_FULL) {
            $timestamp = self::decodeUint24($buffer, $p);
            $messageLength = self::decodeUint24($buffer, $p);
            $messageType = ord($buffer[$p++]);
            $messageStreamId = unpack('N', substr($buffer, $p, 4))[1];
            $p += 4;
        } elseif ($fmt === self::FMT_SAME_STREAM) {
            $timestamp = self::decodeUint24($buffer, $p);
            $messageLength = self::decodeUint24($buffer, $p);
            $messageType = ord($buffer[$p++]);
        } elseif ($fmt === self::FMT_SAME_LENGTH) {
            $timestamp = self::decodeUint24($buffer, $p);
        }
        $consumed = $p - $offset;
        // Extended Timestamp
        if (($fmt === self::FMT_FULL || $fmt === self::FMT_SAME_STREAM || $fmt === self::FMT_SAME_LENGTH) && $timestamp === 0xFFFFFF) {
            if (strlen($buffer) < $p + 4) {
                return null;
            }
            $timestamp = unpack('N', substr($buffer, $p, 4))[1];
            $p += 4;
            $consumed += 4;
        }
        return [
            'timestamp'       => $timestamp,
            'messageLength'   => $messageLength,
            'messageType'     => $messageType,
            'messageStreamId' => $messageStreamId,
            'consumed'        => $consumed,
        ];
    }

    /**
     * 编码 RTMP 握手响应（S0+S1+S2）。
     */
    public static function buildHandshakeResponse(string $c1): string
    {
        // S0
        $s0 = "\x03";
        // S1：1536 字节（time 4 + zero 4 + random 1528）
        $s1 = pack('N', (int)(microtime(true) * 1000) & 0xFFFFFFFF)
            . pack('N', 0)
            . random_bytes(1528);
        // S2：echo C1
        $s2 = substr($c1 . str_repeat("\x00", 1536), 0, 1536);
        return $s0 . $s1 . $s2;
    }

    private static function encodeUint24(int $v): string
    {
        return chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
    }

    private static function decodeUint24(string $b, int &$o): int
    {
        $v = (ord($b[$o]) << 16) | (ord($b[$o + 1]) << 8) | ord($b[$o + 2]);
        $o += 3;
        return $v;
    }
}
