<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

/**
 * 全局唯一 ID 生成器
 *
 * 内部单调递增；进程内不会重复。
 */
final class IdGenerator
{
    private static int $counter = 0;
    private static string $prefix = '';
    private static bool $initialized = false;

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        // 4 字节随机前缀，避免多节点 ID 冲突
        // 8.3 基线直接使用 Random\Randomizer
        self::$prefix = bin2hex(PhpCompat::randomBytes(4));
    }

    /**
     * 生成一个新 ID。
     */
    public static function next(string $prefix = ''): string
    {
        self::init();
        $seq = ++self::$counter;
        $time = (int)(microtime(true) * 1000) & 0xFFFFFFFFFF;
        $tag = $prefix !== '' ? $prefix . ':' : '';
        return self::$prefix . ':' . $tag . $time . ':' . $seq;
    }

    /**
     * 生成一个 16 字节的随机 ID（适合连接 ID）。
     */
    public static function random(): string
    {
        return bin2hex(PhpCompat::randomBytes(8));
    }
}
