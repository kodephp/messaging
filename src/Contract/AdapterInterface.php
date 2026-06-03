<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 协议适配器接口
 *
 * 适配器负责把"协议帧"翻译为"业务 Message/Connection"。
 * 同一协议可以同时实现 Server 与 Client 角色（参考 MQTT）。
 */
interface AdapterInterface
{
    /**
     * 该适配器负责的 URL scheme（ws / sse / mqtt / udp ...）。
     */
    public static function scheme(): string;

    /**
     * 协议版本（如 "3.1.1" / "5.0" / "rfc6455" / "html5"）。
     */
    public function version(): string;

    /**
     * 启动协议配置（建立 socket、注册信号、准备循环）。
     *
     * @param array<string, mixed> $config
     */
    public function boot(array $config): void;

    /**
     * 服务端：开始监听。
     */
    public function listen(string $host, int $port): void;

    /**
     * 客户端：建立连接。
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): ConnectionInterface;

    /**
     * 进入事件循环（阻塞）。
     */
    public function run(): void;

    /**
     * 优雅停机。
     */
    public function shutdown(): void;
}
