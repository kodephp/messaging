<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * 传输层异常（socket / stream 失败）
 */
class TransportException extends MessagingException
{
    public static function openFailed(string $scheme, string $reason, array $context = []): self
    {
        return new self("传输层打开失败 [{$scheme}]: {$reason}", 5005, $context);
    }

    public static function readFailed(string $reason, array $context = []): self
    {
        return new self("传输层读取失败: {$reason}", 5006, $context);
    }

    public static function writeFailed(string $reason, array $context = []): self
    {
        return new self("传输层写入失败: {$reason}", 5007, $context);
    }
}
