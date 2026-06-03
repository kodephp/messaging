<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * Long-Polling 协议异常
 *
 * 适用于 HTTP 长轮询场景：
 *  - 客户端断开
 *  - 队列空（hold 超时）
 *  - 响应写入失败
 */
class LongPollingException extends MessagingException
{
    public static function listenFailed(string $host, int $port, string $reason): self
    {
        return new self(
            "Long-Polling 监听失败 {$host}:{$port}: {$reason}",
            6001,
            ['host' => $host, 'port' => $port, 'reason' => $reason],
        );
    }

    public static function requestInvalid(string $reason, array $context = []): self
    {
        return new self("Long-Polling 请求非法: {$reason}", 6002, $context);
    }

    public static function responseWriteFailed(string $reason): self
    {
        return new self("Long-Polling 响应写入失败: {$reason}", 6003, ['reason' => $reason]);
    }

    public static function holdTimeout(int $ms): self
    {
        return new self("Long-Polling 保持连接超时 {$ms}ms", 6004, ['timeout_ms' => $ms]);
    }

    public static function queueOverflow(string $topic, int $size, int $max): self
    {
        return new self(
            "Long-Polling 队列溢出 topic={$topic} size={$size} max={$max}",
            6005,
            ['topic' => $topic, 'size' => $size, 'max' => $max],
        );
    }
}
