<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

use function constant;
use function sprintf;

/**
 * 包版本号（与 git tag 保持一致；本包 composer.json 不写 version 字段）。
 */
final class Version
{
    public const MAJOR = 3;
    public const MINOR = 4;
    public const PATCH = 1;
    public const PRE_RELEASE = '';

    public static function get(): string
    {
        $version = sprintf('%d.%d.%d', self::MAJOR, self::MINOR, self::PATCH);
        /** @var string $pre 来自常量的运行时值，PHPStan 静态分析可能判定为常量 */
        $pre = (string) constant('self::PRE_RELEASE');
        if ($pre !== '') {
            return $version.'-'.$pre;
        }

        return $version;
    }
}
