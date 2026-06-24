<?php

declare(strict_types=1);

namespace Kode\Messaging\Support;

use Random\Randomizer;

/**
 * 运行时环境检测器
 *
 * 检测当前 PHP 运行在哪种事件循环 / 协程环境中：
 *   - swoole:    ext-swoole 已加载，且在协程上下文中
 *   - swow:      ext-swow 已加载
 *   - workerman: workerman/workerman 包已安装
 *   - plain:     纯 PHP stream 模式（默认）
 *
 * 同时提供 PHP 8.3/8.4/8.5 新特性的运行时检测，
 * 供业务代码在不确定运行时版本时做兼容降级。
 *
 * 用法：
 *   $rt = RuntimeDetector::runtime();        // 'swoole' | 'swow' | 'workerman' | 'plain'
 *   if (RuntimeDetector::inCoroutine()) { ... }
 *   if (RuntimeDetector::hasJsonValidate()) { ... }
 */
final class RuntimeDetector
{
    /**
     * 运行时类型枚举值
     */
    public const RUNTIME_SWOOLE = 'swoole';
    public const RUNTIME_SWOW = 'swow';
    public const RUNTIME_WORKERMAN = 'workerman';
    public const RUNTIME_PLAIN = 'plain';

    /**
     * @var string|null 运行时类型缓存（进程内）
     */
    private static ?string $runtime = null;

    /**
     * @var Randomizer|null Randomizer 实例缓存
     */
    private static ?Randomizer $randomizer = null;

    /**
     * 检测当前运行时环境
     *
     * 优先级：swoole > swow > workerman > plain。
     * 结果在进程内缓存，避免重复反射开销。
     *
     * @return string 返回 self::RUNTIME_* 之一
     */
    public static function runtime(): string
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        if (self::isSwoole()) {
            return self::$runtime = self::RUNTIME_SWOOLE;
        }

        if (self::isSwow()) {
            return self::$runtime = self::RUNTIME_SWOW;
        }

        if (self::isWorkerman()) {
            return self::$runtime = self::RUNTIME_WORKERMAN;
        }

        return self::$runtime = self::RUNTIME_PLAIN;
    }

    /**
     * 是否在 Swoole 协程中
     *
     * 判定条件：ext-swoole 已加载，且当前处于协程上下文（getCid() > 0）。
     *
     * @return bool
     */
    public static function isSwoole(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }
        // 仅当能安全调用时才校验协程上下文
        if (!class_exists(\Swoole\Coroutine::class)) {
            return false;
        }
        try {
            /** @var mixed $cid */
            $cid = \Swoole\Coroutine::getCid();
            return $cid > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 是否在 Swow 协程中
     *
     * 判定条件：ext-swow 已加载。
     *
     * @return bool
     */
    public static function isSwow(): bool
    {
        return extension_loaded('swow');
    }

    /**
     * 是否在 Workerman 事件循环中
     *
     * 判定条件：workerman/workerman 包已安装，且 Worker::$globalEvent 已被赋值
     * （事件循环已启动）。使用反射读取，避免对未安装包的硬依赖。
     *
     * @return bool
     */
    public static function isWorkerman(): bool
    {
        if (!class_exists(\Workerman\Worker::class)) {
            return false;
        }
        try {
            $reflection = new \ReflectionClass(\Workerman\Worker::class);
            if (!$reflection->hasProperty('globalEvent')) {
                // 类存在但属性缺失，保守视为 Workerman 环境
                return true;
            }
            $prop = $reflection->getProperty('globalEvent');
            /** @var mixed $event */
            $event = $prop->getValue();
            return $event !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 是否纯 PHP stream 模式
     *
     * @return bool
     */
    public static function isPlain(): bool
    {
        return self::runtime() === self::RUNTIME_PLAIN;
    }

    /**
     * 是否支持 ext-sockets
     *
     * @return bool
     */
    public static function hasExtSockets(): bool
    {
        return extension_loaded('sockets');
    }

    /**
     * 是否支持 ext-openssl (TLS)
     *
     * @return bool
     */
    public static function hasExtOpenssl(): bool
    {
        return extension_loaded('openssl');
    }

    /**
     * 是否支持 ext-pcntl
     *
     * @return bool
     */
    public static function hasExtPcntl(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * 是否支持 Fiber (PHP 8.1+)
     *
     * @return bool
     */
    public static function hasFiber(): bool
    {
        return class_exists(\Fiber::class);
    }

    /**
     * 是否在协程上下文中（Swoole 或 Swow）
     *
     * @return bool
     */
    public static function inCoroutine(): bool
    {
        return self::isSwoole() || self::isSwow();
    }

    /**
     * 是否支持 PHP 8.3 typed class constants
     *
     * @return bool
     */
    public static function hasTypedClassConstants(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    /**
     * 是否支持 PHP 8.3 #[\Override] 属性
     *
     * @return bool
     */
    public static function hasOverrideAttribute(): bool
    {
        return PHP_VERSION_ID >= 80300;
    }

    /**
     * 是否支持 PHP 8.3 json_validate()
     *
     * @return bool
     */
    public static function hasJsonValidate(): bool
    {
        return function_exists('json_validate');
    }

    /**
     * 是否支持 PHP 8.3 Random\Randomizer
     *
     * @return bool
     */
    public static function hasRandomizer(): bool
    {
        return class_exists(Randomizer::class);
    }

    /**
     * 是否支持 PHP 8.4 property hooks
     *
     * @return bool
     */
    public static function hasPropertyHooks(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    /**
     * 是否支持 PHP 8.4 asymmetric visibility
     *
     * @return bool
     */
    public static function hasAsymmetricVisibility(): bool
    {
        return PHP_VERSION_ID >= 80400;
    }

    /**
     * 是否支持 PHP 8.5 pipe operator
     *
     * 由于无法安全地 eval 检测 `|>` 语法，使用版本号判定。
     *
     * @return bool
     */
    public static function hasPipeOperator(): bool
    {
        return PHP_VERSION_ID >= 80500;
    }

    /**
     * 安全的 JSON 校验（兼容 8.2 和 8.3+）
     *
     * 8.3+ 使用 json_validate()，8.2 降级为 json_decode + json_last_error。
     *
     * @param string $json 待校验的 JSON 字符串
     * @return bool 是否为合法 JSON
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
     * @param int $length 字节长度
     * @return string 随机字节串
     * @throws \Exception 在熵源不可用时抛出
     */
    public static function randomBytes(int $length): string
    {
        if (self::hasRandomizer()) {
            return self::randomizer()->getBytes($length);
        }
        return random_bytes($length);
    }

    /**
     * 获取运行时信息摘要（用于 self-check / 调试）
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'runtime' => self::runtime(),
            'php_version' => PHP_VERSION,
            'php_version_id' => PHP_VERSION_ID,
            'swoole' => self::isSwoole(),
            'swow' => self::isSwow(),
            'workerman' => self::isWorkerman(),
            'ext_sockets' => self::hasExtSockets(),
            'ext_openssl' => self::hasExtOpenssl(),
            'ext_pcntl' => self::hasExtPcntl(),
            'fiber' => self::hasFiber(),
            'in_coroutine' => self::inCoroutine(),
            'typed_class_constants' => self::hasTypedClassConstants(),
            'override_attribute' => self::hasOverrideAttribute(),
            'json_validate' => self::hasJsonValidate(),
            'randomizer' => self::hasRandomizer(),
            'property_hooks' => self::hasPropertyHooks(),
            'asymmetric_visibility' => self::hasAsymmetricVisibility(),
            'pipe_operator' => self::hasPipeOperator(),
        ];
    }

    /**
     * 获取（并缓存）Randomizer 实例
     */
    private static function randomizer(): Randomizer
    {
        return self::$randomizer ??= new Randomizer();
    }
}
