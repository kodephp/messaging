<?php

declare(strict_types=1);

namespace Kode\Messaging;

use Kode\Messaging\Support\PhpCompat;
use Kode\Messaging\Support\RuntimeDetector;

/**
 * 与 kode/process\Kode 风格一致的次级门面
 *
 * 提供 PHP 版本与特性检测、运行时环境检测，调用方式更短。
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
     * 安全的 JSON 校验（8.3 基线直接使用 json_validate）
     */
    public static function jsonValidate(string $json): bool
    {
        return PhpCompat::jsonValidate($json);
    }

    /**
     * 获取安全的随机字节（8.3 基线直接使用 Random\Randomizer）
     *
     * @throws \Exception 在熵源不可用时抛出
     */
    public static function randomBytes(int $length): string
    {
        return PhpCompat::randomBytes($length);
    }

    // ============== 运行时环境检测 ==============

    /**
     * 当前运行时环境：swoole / swow / workerman / plain
     */
    public static function runtime(): string
    {
        return RuntimeDetector::runtime();
    }

    public static function isSwoole(): bool
    {
        return RuntimeDetector::isSwoole();
    }

    public static function isSwow(): bool
    {
        return RuntimeDetector::isSwow();
    }

    public static function isWorkerman(): bool
    {
        return RuntimeDetector::isWorkerman();
    }

    public static function inCoroutine(): bool
    {
        return RuntimeDetector::inCoroutine();
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
