<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

use Random\Randomizer;

/**
 * PHP 8.3/8.4/8.5 特性兼容层
 *
 * 8.3 为最低基线，以下特性可直接使用，无需运行时判断：
 *   - json_validate()
 *   - Random\Randomizer
 *   - typed class constants
 *   - #[\Override]
 *   - readonly class / DNF types / true/false/null standalone types
 *
 * 8.4+ 特性需通过 {@see isPhp84()} 运行时判断：
 *   - property hooks（get/set）
 *   - asymmetric visibility
 *   - #[\Deprecated]
 *
 * 8.5+ 特性需通过 {@see isPhp85()} 运行时判断：
 *   - pipe operator |>
 *   - persistent cURL share handles
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

    /**
     * 是否 PHP 8.3+（基线，始终为 true）。
     */
    public static function isPhp83(): bool
    {
        return self::versionId() >= 80_300;
    }

    /**
     * 是否 PHP 8.4+（property hooks / asymmetric visibility / #[\Deprecated]）。
     */
    public static function isPhp84(): bool
    {
        return self::versionId() >= 80_400;
    }

    /**
     * 是否 PHP 8.5+（pipe operator |> / persistent cURL share handles）。
     */
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
     * 获取（并缓存）Randomizer 实例（8.3 基线直接可用）。
     */
    public static function getRandomizer(): Randomizer
    {
        return self::$randomizer ??= new Randomizer();
    }

    /**
     * 安全的 JSON 校验（8.3 基线直接使用 json_validate）。
     */
    public static function jsonValidate(string $json): bool
    {
        return json_validate($json);
    }

    /**
     * 获取安全的随机字节（8.3 基线直接使用 Random\Randomizer）。
     *
     * @param int $length 字节长度
     * @return string 随机字节串
     */
    public static function randomBytes(int $length): string
    {
        return self::getRandomizer()->getBytes($length);
    }
}
