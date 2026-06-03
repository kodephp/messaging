<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 协议无关连接接口
 *
 * 代表一个客户端（对服务端而言）或一个对端（对客户端而言）。
 */
interface ConnectionInterface
{
    /**
     * 连接唯一 ID（全局或节点内唯一）。
     */
    public function id(): string;

    /**
     * 协议标识：websocket | sse | mqtt | udp ...
     */
    public function protocol(): string;

    /**
     * 远端地址（ip:port）。
     */
    public function remoteAddress(): string;

    /**
     * 发送消息到对端。
     *
     * @param mixed               $payload 业务载荷（已编码）
     * @param array<string,mixed> $options 协议特定选项（event / qos / binary / topic ...）
     */
    public function send(mixed $payload, array $options = []): bool;

    /**
     * 关闭连接。
     *
     * @param int    $code   协议关闭码（WebSocket 1000、MQTT reason code 等）
     * @param string $reason 关闭原因
     */
    public function close(int $code = 1000, string $reason = ''): void;

    /**
     * 当前是否处于打开状态。
     */
    public function isOpen(): bool;

    /**
     * 业务附加属性容器。
     *
     * @return array<string, mixed>
     */
    public function attributes(): array;

    /**
     * 设置属性。
     */
    public function setAttribute(string $key, mixed $value): void;

    /**
     * 获取属性。
     */
    public function getAttribute(string $key, mixed $default = null): mixed;

    /**
     * 注册连接关闭时的回调（一次性）。
     *
     * @param callable(\Throwable|null): void $callback
     */
    public function onClose(callable $callback): void;
}
