<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

use Random\Randomizer;

/**
 * PHP 8.2/8.3/8.4/8.5 特性兼容层
 *
 * 业务代码统一通过该类判断版本与特性，避免到处写版本判断。
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
        // 8.5 之前没有内建函数检测点，只能用版本号
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
}
