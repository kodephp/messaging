<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * CoAP 协议异常（RFC 7252）
 *
 * 包含 CoAP 响应码到异常的映射工具方法：
 *   - 4.xx 客户端错误（业务可恢复）
 *   - 5.xx 服务端错误
 *   - 协议层错误（解析失败、Token 错误、选项错误）
 */
class CoapException extends MessagingException
{
    public static function bindFailed(string $host, int $port, string $reason): self
    {
        return new self(
            "CoAP 绑定失败 {$host}:{$port}: {$reason}",
            7001,
            ['host' => $host, 'port' => $port, 'reason' => $reason],
        );
    }

    public static function packetParseFailed(string $reason, array $context = []): self
    {
        return new self("CoAP 数据包解析失败: {$reason}", 7002, $context);
    }

    public static function packetEncodeFailed(string $reason, array $context = []): self
    {
        return new self("CoAP 数据包编码失败: {$reason}", 7003, $context);
    }

    /**
     * 由 CoAP 响应码创建异常。
     *
     * CoAP 响应码格式：CD.DD（如 4.01）
     *  - 1.xx Success
     *  - 2.xx Success（2.05 Content 等）
     *  - 4.xx Client Error
     *  - 5.xx Server Error
     */
    public static function fromResponseCode(float $code, string $reason = ''): self
    {
        $class = match (true) {
            $code >= 4.00 && $code < 5.00 => '客户端错误',
            $code >= 5.00                 => '服务端错误',
            default                       => 'CoAP 错误',
        };
        return new self(
            "CoAP {$class} " . number_format($code, 2) . ($reason !== '' ? ": {$reason}" : ''),
            (int)($code * 100),
            ['response_code' => $code, 'reason' => $reason],
        );
    }

    public static function tokenMismatch(int $expected, int $actual): self
    {
        return new self(
            "CoAP Token 不匹配: expected={$expected}, actual={$actual}",
            7004,
            ['expected' => $expected, 'actual' => $actual],
        );
    }

    public static function messageIdMismatch(int $expected, int $actual): self
    {
        return new self(
            "CoAP Message ID 不匹配: expected={$expected}, actual={$actual}",
            7005,
            ['expected' => $expected, 'actual' => $actual],
        );
    }

    public static function retransmitExhausted(int $retries, int $timeoutMs): self
    {
        return new self(
            "CoAP 重传耗尽 retries={$retries} timeout={$timeoutMs}ms",
            7006,
            ['retries' => $retries, 'timeout_ms' => $timeoutMs],
        );
    }
}
