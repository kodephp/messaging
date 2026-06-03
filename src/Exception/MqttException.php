<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * MQTT 协议异常
 */
class MqttException extends MessagingException
{
    public const REASON_OK                      = 0;
    public const REASON_UNSPECIFIED             = 128;
    public const REASON_MALFORMED_PACKET        = 129;
    public const REASON_PROTOCOL_ERROR          = 130;
    public const REASON_IMPLEMENTATION_ERROR    = 131;
    public const REASON_UNSUPPORTED_VERSION     = 132;
    public const REASON_CLIENT_IDENTIFIER       = 133;
    public const REASON_BAD_USERNAME_PASSWORD   = 134;
    public const REASON_NOT_AUTHORIZED          = 135;
    public const REASON_SERVER_UNAVAILABLE      = 136;

    public static function connectFailed(string $reason, array $context = []): self
    {
        return new self("MQTT 连接失败: {$reason}", 5001, $context);
    }

    public static function malformedPacket(string $reason, array $context = []): self
    {
        return new self("MQTT 协议包错误: {$reason}", self::REASON_MALFORMED_PACKET, $context);
    }

    public static function authenticationFailed(array $context = []): self
    {
        return new self(
            "MQTT 鉴权失败",
            self::REASON_BAD_USERNAME_PASSWORD,
            $context,
        );
    }
}
