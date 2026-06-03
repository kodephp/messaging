<?php

declare(strict_types=1);

namespace Kode\Messaging;

use Kode\Messaging\Support\PhpCompat;

/**
 * 与 kode/process\Kode 风格一致的次级门面
 *
 * 提供 PHP 版本与特性检测，调用方式更短。
 */
final class Kode
{
    private function __construct()
    {
    }

    public static function version(): string
    {
        return Messaging::version();
    }

    public static function phpVersion(): string
    {
        return PhpCompat::version();
    }

    public static function phpVersionId(): int
    {
        return PhpCompat::versionId();
    }

    public static function isPhp82(): bool
    {
        return PhpCompat::isPhp82();
    }

    public static function isPhp83(): bool
    {
        return PhpCompat::isPhp83();
    }

    public static function isPhp84(): bool
    {
        return PhpCompat::isPhp84();
    }

    public static function isPhp85(): bool
    {
        return PhpCompat::isPhp85();
    }

    public static function hasPipeOperator(): bool
    {
        return PhpCompat::hasPipeOperator();
    }

    /**
     * 与 PHP 8.5 |> 等价的多步骤链式处理（不依赖 8.5 即可用）。
     *
     * @template T
     * @param T                       $input
     * @param list<callable(T): T>    $stages
     * @return T
     */
    public static function pipe(mixed $input, array $stages): mixed
    {
        $value = $input;
        foreach ($stages as $stage) {
            $value = $stage($value);
        }
        return $value;
    }
}
