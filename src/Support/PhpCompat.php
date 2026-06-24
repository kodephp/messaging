<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

use Random\Randomizer;

/**
 * PHP 8.2/8.3/8.4/8.5 特性兼容层
 *
 * 业务代码统一通过该类判断版本与特性，避免到处写版本判断。
 *
 * 本类聚焦于 **PHP 语言版本特性** 检测；
 * 运行时环境（Swoole/Swow/Workerman）检测由 {@see RuntimeDetector} 负责。
 */
final class PhpCompat
{
    private static ?int $versionId = null;
    private static ?Randomizer $randomizer = null;

    public static function version(): string
    {
        return PHP_VERSION;
    }

    public static function versionId(): int
    {
        return self::$versionId ??= PHP_VERSION_ID;
    }

    public static function isPhp82(): bool
    {
        return self::versionId() >= 80_200;
    }

    public static function isPhp83(): bool
    {
        return self::versionId() >= 80_300;
    }

    public static function isPhp84(): bool
    {
        return self::versionId() >= 80_400;
    }

    public static function isPhp85(): bool
    {
        return self::versionId() >= 80_500;
    }

    /**
     * PHP 8.5 才有 |> 管道操作符。
     */
    public static function hasPipeOperator(): bool
    {
        return self::isPhp85();
    }

    /**
     * PHP 8.3 Random\Randomizer。
     */
    public static function hasRandomizer(): bool
    {
        return self::isPhp83() && class_exists(Randomizer::class);
    }

    public static function getRandomizer(): Randomizer
    {
        return self::$randomizer ??= new Randomizer();
    }

    /**
     * PHP 8.2 引入 true/false/null 独立类型。
     */
    public static function hasStandaloneTypes(): bool
    {
        return self::isPhp82();
    }

    /**
     * PHP 8.3 引入 json_validate()。
     */
    public static function hasJsonValidate(): bool
    {
        return function_exists('json_validate');
    }

    /**
     * PHP 8.3 引入 typed class constants。
     */
    public static function hasTypedClassConstants(): bool
    {
        return self::isPhp83();
    }

    /**
     * PHP 8.3 引入 #[\Override] 属性。
     */
    public static function hasOverrideAttribute(): bool
    {
        return self::isPhp83();
    }

    /**
     * PHP 8.4 引入 property hooks。
     */
    public static function hasPropertyHooks(): bool
    {
        return self::isPhp84();
    }

    /**
     * PHP 8.4 引入 asymmetric visibility。
     */
    public static function hasAsymmetricVisibility(): bool
    {
        return self::isPhp84();
    }

    /**
     * 安全的 JSON 校验（兼容 8.2 和 8.3+）
     *
     * 8.3+ 使用 json_validate()，8.2 降级为 json_decode + json_last_error。
     */
    public static function jsonValidate(string $json): bool
    {
        if (self::hasJsonValidate()) {
            return json_validate($json);
        }
        json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * 获取安全的随机字节（兼容 8.2 和 8.3+）
     *
     * 8.3+ 优先使用 Random\Randomizer，8.2 降级为 random_bytes。
     *
     * @throws \Exception 在熵源不可用时抛出
     */
    public static function randomBytes(int $length): string
    {
        if (self::hasRandomizer()) {
            return self::getRandomizer()->getBytes($length);
        }
        return random_bytes($length);
    }
}
