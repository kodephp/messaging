<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * WebTransport 协议异常
 *
 * 状态码区间：8301-8399
 * WebTransport 参考：https://w3c.github.io/webtransport/
 */
class WebTransportException extends MessagingException
{
    public static function handshakeFailed(string $reason, array $context = []): self
    {
        return new self("WebTransport 握手失败: {$reason}", 8301, $context);
    }

    public static function sessionError(string $reason, array $context = []): self
    {
        return new self("WebTransport 会话错误: {$reason}", 8302, $context);
    }

    public static function streamError(string $reason, array $context = []): self
    {
        return new self("WebTransport 流错误: {$reason}", 8303, $context);
    }

    public static function datagramError(string $reason, array $context = []): self
    {
        return new self("WebTransport Datagram 错误: {$reason}", 8304, $context);
    }
}
