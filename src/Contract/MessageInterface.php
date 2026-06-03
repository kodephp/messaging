<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 协议无关消息体接口
 *
 * 业务层面向此接口编程；具体协议适配器负责把协议帧解析/封装到 Message 中。
 *
 * 不可变性：with* 方法返回新对象，原对象不变。
 */
interface MessageInterface
{
    /**
     * 消息唯一 ID（连接内单调递增）。
     */
    public function id(): string;

    /**
     * 事件名（WS/SSE 习惯用 "user.created" 形式；MQTT 走 topic）。
     */
    public function event(): ?string;

    /**
     * 主题（MQTT/Redis 等 Pub/Sub 协议使用）。
     */
    public function topic(): ?string;

    /**
     * 业务载荷（已解码）。可任意类型。
     *
     * @return mixed
     */
    public function payload(): mixed;

    /**
     * 原始字节流（未解码）。
     */
    public function raw(): string;

    /**
     * 附加头。
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * MQTT QoS（0/1/2）；其他协议返回 0。
     */
    public function qos(): int;

    /**
     * 是否二进制帧（WebSocket 区分 text/binary）。
     */
    public function isBinary(): bool;

    /**
     * 是否保留消息（MQTT retain）。
     */
    public function isRetain(): bool;

    /**
     * 协议标识（websocket / sse / mqtt / udp ...）。
     */
    public function protocol(): string;

    /**
     * 到达时间戳（毫秒）。
     */
    public function timestamp(): int;

    /**
     * 上下文信息（trace id、connection id、user id 等）。
     *
     * @return array<string, mixed>
     */
    public function context(): array;

    /**
     * 返回携带新 payload 的新 Message 对象。
     *
     * @param mixed $payload
     */
    public function withPayload(mixed $payload): self;

    /**
     * 返回携带新 event 的新 Message 对象。
     */
    public function withEvent(?string $event): self;
}
