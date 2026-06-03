<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 发布订阅总线接口
 *
 * 跨协议 / 跨进程 / 跨节点统一事件总线。
 * 主题支持通配符（* 单级 / # 多级）。
 */
interface BusInterface
{
    /**
     * 订阅主题。
     *
     * @param callable(array<string, mixed>, AcknowledgeInterface): void $handler
     * @param array<string, mixed>                                       $options
     *        - qos     : 0|1|2
     *        - shared  : string 共享订阅组名（集群负载均衡）
     *        - pattern : 'mqtt' | 'exact'（默认 mqtt）
     */
    public function subscribe(string $topic, callable $handler, array $options = []): string;

    /**
     * 取消订阅。
     */
    public function unsubscribe(string $subscriptionId): void;

    /**
     * 发布消息。
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *        - qos    : 0|1|2
     *        - delay  : int 延迟秒数（需要 kode/queue）
     */
    public function publish(string $topic, array $payload, array $options = []): void;

    /**
     * 驱动名。
     */
    public function driver(): string;
}
