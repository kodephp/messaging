<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * WebSocket 协议异常（RFC 6455）
 *
 * 常见 Close Code（与 RFC 6455 一致）：
 *   - 1000 正常关闭
 *   - 1001 端点离开
 *   - 1002 协议错误
 *   - 1003 不支持的数据类型
 *   - 1008 策略违规
 *   - 1009 消息过大
 *   - 1011 服务器内部错误
 */
class WebSocketException extends MessagingException
{
    public const CODE_NORMAL = 1000;
    public const CODE_GOING_AWAY = 1001;
    public const CODE_PROTOCOL_ERROR = 1002;
    public const CODE_UNSUPPORTED_DATA = 1003;
    public const CODE_INVALID_PAYLOAD = 1007;
    public const CODE_POLICY_VIOLATION = 1008;
    public const CODE_MESSAGE_TOO_BIG = 1009;
    public const CODE_INTERNAL_ERROR = 1011;

    public static function handshakeFailed(string $reason, array $context = []): self
    {
        return new self("WebSocket 握手失败: {$reason}", 4001, $context);
    }

    public static function invalidFrame(string $reason, array $context = []): self
    {
        return new self("WebSocket 帧错误: {$reason}", self::CODE_PROTOCOL_ERROR, $context);
    }

    public static function messageTooBig(int $size, int $max): self
    {
        return new self(
            "WebSocket 消息过大: {$size} bytes (max: {$max})",
            self::CODE_MESSAGE_TOO_BIG,
            ['size' => $size, 'max' => $max],
        );
    }

    public static function originNotAllowed(string $origin): self
    {
        return new self(
            "Origin 不被允许: {$origin}",
            self::CODE_POLICY_VIOLATION,
            ['origin' => $origin],
        );
    }
}
