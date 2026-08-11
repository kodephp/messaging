<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

use Kode\Messaging\Exception\TransportException;
use Throwable;

/**
 * 传输层工厂 —— 根据配置和运行时环境自动选择最佳传输驱动。
 *
 * 优先级（auto 模式）：
 *   1. swoole     （如果 ext-swoole 已加载）
 *   2. swow       （如果 ext-swow 已加载）
 *   3. workerman  （如果 workerman/workerman 已安装）
 *   4. sockets    （如果 ext-sockets 已加载，暂未实现独立驱动，回退 stream）
 *   5. stream     （始终可用，基准实现）
 *
 * 用法：
 *   $transport = TransportFactory::create();              // 自动检测
 *   $transport = TransportFactory::create('swoole');      // 强制指定
 *   $driver    = TransportFactory::detect();              // 仅检测不创建
 */
final class TransportFactory
{
    /**
     * 已创建的实例缓存（按驱动名）。
     *
     * @var array<string, TransportInterface>
     */
    private static array $instances = [];

    /**
     * 创建传输层实例。
     *
     * @param null|string $driver 驱动名；null 表示自动检测最佳驱动
     *
     * @return TransportInterface 传输层实例（同一驱动复用单例）
     *
     * @throws TransportException 当指定驱动不可用或不支持
     */
    public static function create(?string $driver = null): TransportInterface
    {
        $driver ??= self::detect();

        if (isset(self::$instances[$driver])) {
            return self::$instances[$driver];
        }

        $instance = self::instantiate($driver);
        self::$instances[$driver] = $instance;

        return $instance;
    }

    /**
     * 检测当前环境可用的最佳驱动（不创建实例）。
     *
     * @return string 驱动名，见 TransportInterface::DRIVER_*
     */
    public static function detect(): string
    {
        if (self::isSwoole()) {
            return TransportInterface::DRIVER_SWOOLE;
        }

        if (self::isSwow()) {
            return TransportInterface::DRIVER_SWOW;
        }

        if (self::isWorkerman()) {
            return TransportInterface::DRIVER_WORKERMAN;
        }

        // ext-sockets 暂未实现独立驱动，回退到 stream
        // 未来可增加 SocketsTransport 使用 ext-sockets 原生 API
        return TransportInterface::DRIVER_STREAM;
    }

    /**
     * 是否在 Swoole 运行时中。
     */
    public static function isSwoole(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine\Socket::class);
    }

    /**
     * 是否在 Swow 运行时中。
     */
    public static function isSwow(): bool
    {
        return extension_loaded('swow')
            && class_exists(\Swow\Socket::class);
    }

    /**
     * 是否安装了 Workerman。
     */
    public static function isWorkerman(): bool
    {
        return class_exists(\Workerman\Worker::class);
    }

    /**
     * 是否加载了 ext-sockets 扩展。
     */
    public static function hasExtSockets(): bool
    {
        return extension_loaded('sockets');
    }

    /**
     * 重置实例缓存（主要用于测试）。
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    /**
     * 实例化指定驱动的传输层。
     *
     * @param string $driver 驱动名
     *
     *
     * @throws TransportException 当驱动不可用或不支持
     */
    private static function instantiate(string $driver): TransportInterface
    {
        try {
            return match ($driver) {
                TransportInterface::DRIVER_STREAM => new StreamTransport(),
                TransportInterface::DRIVER_SWOOLE => new SwooleTransport(),
                TransportInterface::DRIVER_SWOW => new SwowTransport(),
                TransportInterface::DRIVER_WORKERMAN => new WorkermanTransport(),
                TransportInterface::DRIVER_SOCKETS => new StreamTransport(), // 暂回退到 stream
                default => throw TransportException::openFailed(
                    $driver,
                    "不支持的传输驱动: {$driver}",
                ),
            };
        } catch (TransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw TransportException::openFailed(
                $driver,
                "传输驱动 [{$driver}] 初始化失败: {$e->getMessage()}",
            );
        }
    }
}
