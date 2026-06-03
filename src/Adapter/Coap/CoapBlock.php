<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

use Kode\Messaging\Exception\CoapException;

/**
 * CoAP Block 辅助（RFC 7959）
 *
 * Block option 是 0-3 字节变长编码（0-3 字节）：
 *   - num: 0 .. 2^20-1 (block number)
 *   - M:   1 bit (more flag)
 *   - szx: 3 bits (block size exponent: 0..6 → 16..1024)
 *
 * 字节布局：
 *   - 当 num < 16：1 字节
 *   - 当 num < 4096：2 字节
 *   - 其它：3 字节
 */
final class CoapBlock
{
    /** 合法 szx 范围 */
    public const SZX_MIN = 0;  // 16 字节
    public const SZX_MAX = 6;  // 1024 字节

    /**
     * 编码 Block option（变长）。
     *
     * - 1 字节：num 0-15
     * - 2 字节：num 16-4095
     * - 3 字节：num 4096-1048575
     *
     * @return string 0-3 字节
     */
    public static function encode(int $num, bool $more, int $szx): string
    {
        if ($szx < self::SZX_MIN || $szx > self::SZX_MAX) {
            throw CoapException::packetEncodeFailed(
                'invalid block size exponent',
                ['szx' => $szx],
            );
        }
        if ($num < 0 || $num >= 1 << 20) {
            throw CoapException::packetEncodeFailed(
                'block number out of range',
                ['num' => $num],
            );
        }
        $tail = ((int)$num << 4) | ($more ? 0x08 : 0) | ($szx & 0x07);
        if ($num < 16) {
            return chr($tail & 0xFF);
        }
        if ($num < 4096) {
            return chr(($num >> 4) & 0xFF) . chr($tail & 0xFF);
        }
        return chr(($num >> 12) & 0xFF) . chr(($num >> 4) & 0xFF) . chr($tail & 0xFF);
    }

    /**
     * 解码 Block option。
     *
     * @return array{num:int, more:bool, szx:int, size:int}
     */
    public static function decode(string $bytes): array
    {
        $len = strlen($bytes);
        if ($len < 1 || $len > 3) {
            throw CoapException::packetParseFailed(
                'invalid block length',
                ['len' => $len],
            );
        }
        // 最后一个字节：低 3 位 = szx，bit 3 = M，高 4 位 = num 的低 4 位
        $last = ord($bytes[$len - 1]);
        $szx = $last & 0x07;
        if ($szx > self::SZX_MAX) {
            throw CoapException::packetParseFailed('invalid szx', ['szx' => $szx]);
        }
        $more = ($last & 0x08) !== 0;
        $numLow = ($last >> 4) & 0x0F;

        // 前面的字节：每个 8 位组成 num 的高位
        $num = $numLow;
        for ($i = $len - 2; $i >= 0; $i--) {
            $num |= ord($bytes[$i]) << (4 + 8 * ($len - 2 - $i));
        }
        $size = 16 << $szx;
        return ['num' => $num, 'more' => $more, 'szx' => $szx, 'size' => $size];
    }

    /**
     * 把 payload 按 szx 切分为 block 序列。
     *
     * @return list<array{num:int, more:bool, szx:int, data:string}>
     */
    public static function split(string $payload, int $szx = 3): array
    {
        if ($szx < self::SZX_MIN || $szx > self::SZX_MAX) {
            throw CoapException::packetEncodeFailed('invalid szx', ['szx' => $szx]);
        }
        $size = 16 << $szx;
        $blocks = [];
        $offset = 0;
        $i = 0;
        $len = strlen($payload);
        while ($offset < $len) {
            $chunk = substr($payload, $offset, $size);
            $offset += $size;
            $more = $offset < $len;
            $blocks[] = [
                'num'  => $i++,
                'more' => $more,
                'szx'  => $szx,
                'data' => $chunk,
            ];
        }
        return $blocks;
    }
}
