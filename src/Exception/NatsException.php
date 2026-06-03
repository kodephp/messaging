<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * NATS 协议异常
 *
 * NATS 协议参考：https://docs.nats.io/reference/reference-protocols/nats-protocol
 *
 * 状态码区间：8001-8099
 */
class NatsException extends MessagingException
{
    public static function connectFailed(string $reason, array $context = []): self
    {
        return new self("NATS 连接失败: {$reason}", 8001, $context);
    }

    public static function protocolError(string $reason, array $context = []): self
    {
        return new self("NATS 协议错误: {$reason}", 8002, $context);
    }

    public static function invalidMessage(string $reason, array $context = []): self
    {
        return new self("NATS 消息格式错误: {$reason}", 8003, $context);
    }

    public static function maxPayloadExceeded(int $size, int $max): self
    {
        return new self("NATS 消息超限: size={$size}, max={$max}", 8004, [
            'size' => $size, 'max' => $max,
        ]);
    }

    public static function serverError(string $reason, array $context = []): self
    {
        return new self("NATS 服务端错误: {$reason}", 8005, $context);
    }
}
