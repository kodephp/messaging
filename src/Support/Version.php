<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

/**
 * 包版本号（与 composer.json 中 version 字段保持一致）。
 */
final class Version
{
    public const MAJOR = 2;
    public const MINOR = 1;
    public const PATCH = 0;
    public const PRE_RELEASE = '';

    public static function get(): string
    {
        $version = \sprintf('%d.%d.%d', self::MAJOR, self::MINOR, self::PATCH);
        /** @var string $pre 来自常量的运行时值，PHPStan 静态分析可能判定为常量 */
        $pre = (string)\constant('self::PRE_RELEASE');
        if ($pre !== '') {
            return $version . '-' . $pre;
        }
        return $version;
    }
}
