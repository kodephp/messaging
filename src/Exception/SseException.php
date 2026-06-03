<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * SSE 协议异常
 */
class SseException extends MessagingException
{
    public static function invalidEvent(string $reason, array $context = []): self
    {
        return new self("SSE 事件错误: {$reason}", 4002, $context);
    }

    public static function connectionClosed(string $reason, array $context = []): self
    {
        return new self("SSE 连接关闭: {$reason}", 4003, $context);
    }
}
