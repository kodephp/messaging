<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * STOMP 协议异常
 *
 * STOMP 1.0/1.1/1.2 协议参考：https://stomp.github.io/stomp-specification-1.2.html
 *
 * 状态码区间：8101-8199
 */
class StompException extends MessagingException
{
    public static function connectFailed(string $reason, array $context = []): self
    {
        return new self("STOMP 连接失败: {$reason}", 8101, $context);
    }

    public static function protocolError(string $reason, array $context = []): self
    {
        return new self("STOMP 协议错误: {$reason}", 8102, $context);
    }

    public static function parseFailed(string $reason, array $context = []): self
    {
        return new self("STOMP 帧解析失败: {$reason}", 8103, $context);
    }

    public static function serverError(string $reason, array $context = []): self
    {
        return new self("STOMP 服务端错误: {$reason}", 8104, $context);
    }
}
