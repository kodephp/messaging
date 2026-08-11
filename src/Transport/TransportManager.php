<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

/**
 * 传输层管理器 —— 持有全局传输层单例并提供访问。
 *
 * 协议适配器通过 TransportManager::get() 获取当前传输层实例，
 * 无需关心具体驱动。首次调用时自动通过 TransportFactory 检测并创建。
 *
 * 用法：
 *   $transport = TransportManager::get();                 // 获取全局实例
 *   TransportManager::set(new StreamTransport());         // 手动注入（测试用）
 *   TransportManager::reset();                            // 重置（测试用）
 */
final class TransportManager
{
    /**
     * 全局传输层实例。
     */
    private static ?TransportInterface $transport = null;

    /**
     * 获取全局传输层实例。
     *
     * 首次调用时通过 TransportFactory 自动检测并创建；
     * 后续调用返回同一实例（单例）。
     */
    public static function get(): TransportInterface
    {
        if (self::$transport === null) {
            self::$transport = TransportFactory::create();
        }

        return self::$transport;
    }

    /**
     * 手动设置传输层实例（主要用于测试或强制指定驱动）。
     *
     * @param TransportInterface $transport 传输层实例
     */
    public static function set(TransportInterface $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * 重置全局实例（主要用于测试）。
     *
     * 下次调用 get() 时将重新通过 TransportFactory 创建。
     */
    public static function reset(): void
    {
        self::$transport = null;
    }

    /**
     * 获取当前传输层驱动名（便捷方法）。
     *
     * @return string 驱动名，见 TransportInterface::DRIVER_*
     */
    public static function driver(): string
    {
        return self::get()->driver();
    }
}
