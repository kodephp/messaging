<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * UDP / Datagram 协议异常
 */
class UdpException extends MessagingException
{
    public static function bindFailed(string $host, int $port, string $reason): self
    {
        return new self(
            "UDP 绑定失败 {$host}:{$port}: {$reason}",
            5002,
            ['host' => $host, 'port' => $port, 'reason' => $reason],
        );
    }

    public static function packetTooBig(int $size, int $max): self
    {
        return new self(
            "UDP 数据报过大: {$size} bytes (max: {$max})",
            5003,
            ['size' => $size, 'max' => $max],
        );
    }

    public static function sendFailed(string $reason, array $context = []): self
    {
        return new self("UDP 发送失败: {$reason}", 5004, $context);
    }
}
