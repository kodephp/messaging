<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * RTMP 协议异常
 *
 * 状态码区间：8401-8499
 */
class RtmpException extends MessagingException
{
    public static function handshakeFailed(string $reason, array $context = []): self
    {
        return new self("RTMP 握手失败: {$reason}", 8401, $context);
    }

    public static function chunkError(string $reason, array $context = []): self
    {
        return new self("RTMP Chunk 错误: {$reason}", 8402, $context);
    }

    public static function amfError(string $reason, array $context = []): self
    {
        return new self("RTMP AMF 错误: {$reason}", 8403, $context);
    }

    public static function serverError(string $reason, array $context = []): self
    {
        return new self("RTMP 服务端错误: {$reason}", 8404, $context);
    }
}
