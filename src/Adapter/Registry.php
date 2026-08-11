<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter;

use Kode\Messaging\Contract\AdapterInterface;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\AdapterNotFoundException;

/**
 * 协议适配器注册表
 *
 * scheme 不区分大小写（ws / WS / Ws 等价）。
 * 通过 Messaging::register() 注册，Builder 通过 Registry::find() 查找。
 */
final class Registry
{
    /**
     * @var array<string, class-string<AdapterInterface>>
     */
    private static array $adapters = [];

    /**
     * 注册一个协议适配器。
     *
     * @param class-string<AdapterInterface> $adapterClass
     */
    public static function register(string $scheme, string $adapterClass): void
    {
        self::$adapters[strtolower($scheme)] = $adapterClass;
    }

    /**
     * 注销一个协议适配器。
     */
    public static function unregister(string $scheme): void
    {
        unset(self::$adapters[strtolower($scheme)]);
    }

    /**
     * 查找适配器。
     *
     * @return null|class-string<AdapterInterface>
     */
    public static function find(string $scheme): ?string
    {
        return self::$adapters[strtolower($scheme)] ?? null;
    }

    /**
     * 强制查找，找不到抛异常。
     *
     * @return class-string<AdapterInterface>
     */
    public static function findOrFail(string $scheme): string
    {
        $found = self::find($scheme);
        if ($found === null) {
            throw AdapterNotFoundException::forScheme($scheme, self::schemes());
        }

        return $found;
    }

    /**
     * 创建协议适配器实例。
     */
    public static function make(string $scheme): AdapterInterface
    {
        $class = self::findOrFail($scheme);

        return new $class();
    }

    /**
     * 创建指定 scheme 的 Connection 实例（client 端）。
     */
    public static function connect(string $scheme, array $config = []): ConnectionInterface
    {
        return self::make($scheme)->connect($config);
    }

    /**
     * 已注册的 scheme 列表。
     *
     * @return list<string>
     */
    public static function schemes(): array
    {
        return array_keys(self::$adapters);
    }

    /**
     * 是否已注册。
     */
    public static function has(string $scheme): bool
    {
        return isset(self::$adapters[strtolower($scheme)]);
    }

    /**
     * 重置（仅测试用）。
     */
    public static function reset(): void
    {
        self::$adapters = [];
    }
}
