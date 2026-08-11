<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

/**
 * MQTT 5.0 原因码（Reason Code）
 *
 * MQTT 5.0 用 Reason Code 替代了 3.1.1 的 Return Code，
 * 覆盖 CONNACK / PUBACK / PUBREC / PUBREL / PUBCOMP / SUBACK / UNSUBACK / DISCONNECT / AUTH。
 *
 * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html#_Toc3901031
 */
final class ReasonCode
{
    // ===================== 通用 =====================

    /** 成功 */
    public const SUCCESS = 0x00;

    /** 接受但存在 QoS 降级（SUBACK） */
    public const GRANTED_QOS_0 = 0x00;
    public const GRANTED_QOS_1 = 0x01;
    public const GRANTED_QOS_2 = 0x02;

    /** 带认证继续（AUTH） */
    public const CONTINUE_AUTHENTICATION = 0x18;

    /** 重新认证（AUTH） */
    public const REAUTHENTICATE = 0x19;

    // ===================== 错误（0x80+）=====================

    /** 未指定错误 */
    public const UNSPECIFIED_ERROR = 0x80;

    /** 畸形包 */
    public const MALFORMED_PACKET = 0x81;

    /** 协议错误 */
    public const PROTOCOL_ERROR = 0x82;

    /** 实现规范错误 */
    public const IMPLEMENTATION_SPECIFIC_ERROR = 0x83;

    /** 不支持的协议版本 */
    public const UNSUPPORTED_PROTOCOL_VERSION = 0x84;

    /** 客户端 ID 无效 */
    public const CLIENT_IDENTIFIER_NOT_VALID = 0x85;

    /** 用户名/密码错误 */
    public const BAD_USERNAME_OR_PASSWORD = 0x86;

    /** 未授权 */
    public const NOT_AUTHORIZED = 0x87;

    /** 服务端不可用 */
    public const SERVER_UNAVAILABLE = 0x88;

    /** 服务端繁忙 */
    public const SERVER_BUSY = 0x89;

    /** 禁止（主题被禁止） */
    public const BANNED = 0x8A;

    /** 主题过滤器无效 */
    public const TOPIC_FILTER_INVALID = 0x8F;

    /** 主题名无效 */
    public const TOPIC_NAME_INVALID = 0x90;

    /** 包标识符已被使用 */
    public const PACKET_IDENTIFIER_IN_USE = 0x91;

    /** 包标识符无效 */
    public const PACKET_IDENTIFIER_NOT_FOUND = 0x92;

    /** 超出配额 */
    public const QUOTA_EXCEEDED = 0x97;

    /** 载荷格式无效 */
    public const PAYLOAD_FORMAT_INVALID = 0x99;

    /** 不支持保留消息 */
    public const RETAIN_NOT_SUPPORTED = 0x9A;

    /** 不支持的 QoS */
    public const QOS_NOT_SUPPORTED = 0x9B;

    /** 使用另一服务端 */
    public const USE_ANOTHER_SERVER = 0x9C;

    /** 服务端已迁移 */
    public const SERVER_MOVED = 0x9D;

    /** 不支持共享订阅 */
    public const SHARED_SUBSCRIPTIONS_NOT_SUPPORTED = 0x9E;

    /** 连接速率超限 */
    public const CONNECTION_RATE_EXCEEDED = 0x9F;

    /** 订阅标识符不支持 */
    public const SUBSCRIPTION_IDENTIFIERS_NOT_SUPPORTED = 0xA1;

    /** 通配符订阅不支持 */
    public const WILDCARD_SUBSCRIPTIONS_NOT_SUPPORTED = 0xA2;

    /**
     * 判断原因码是否为成功。
     */
    public static function isSuccess(int $code): bool
    {
        return $code < 0x80;
    }

    /**
     * 判断原因码是否为错误。
     */
    public static function isError(int $code): bool
    {
        return $code >= 0x80;
    }

    /**
     * 将原因码转换为可读描述。
     */
    public static function description(int $code): string
    {
        return match ($code) {
            self::SUCCESS => 'Success',
            self::GRANTED_QOS_1 => 'Granted QoS 1',
            self::GRANTED_QOS_2 => 'Granted QoS 2',
            self::CONTINUE_AUTHENTICATION => 'Continue authentication',
            self::REAUTHENTICATE => 'Re-authenticate',
            self::UNSPECIFIED_ERROR => 'Unspecified error',
            self::MALFORMED_PACKET => 'Malformed packet',
            self::PROTOCOL_ERROR => 'Protocol error',
            self::IMPLEMENTATION_SPECIFIC_ERROR => 'Implementation specific error',
            self::UNSUPPORTED_PROTOCOL_VERSION => 'Unsupported protocol version',
            self::CLIENT_IDENTIFIER_NOT_VALID => 'Client identifier not valid',
            self::BAD_USERNAME_OR_PASSWORD => 'Bad username or password',
            self::NOT_AUTHORIZED => 'Not authorized',
            self::SERVER_UNAVAILABLE => 'Server unavailable',
            self::SERVER_BUSY => 'Server busy',
            self::BANNED => 'Banned',
            self::TOPIC_FILTER_INVALID => 'Topic filter invalid',
            self::TOPIC_NAME_INVALID => 'Topic name invalid',
            self::PACKET_IDENTIFIER_IN_USE => 'Packet identifier in use',
            self::PACKET_IDENTIFIER_NOT_FOUND => 'Packet identifier not found',
            self::QUOTA_EXCEEDED => 'Quota exceeded',
            self::PAYLOAD_FORMAT_INVALID => 'Payload format invalid',
            self::RETAIN_NOT_SUPPORTED => 'Retain not supported',
            self::QOS_NOT_SUPPORTED => 'QoS not supported',
            self::USE_ANOTHER_SERVER => 'Use another server',
            self::SERVER_MOVED => 'Server moved',
            self::SHARED_SUBSCRIPTIONS_NOT_SUPPORTED => 'Shared subscriptions not supported',
            self::CONNECTION_RATE_EXCEEDED => 'Connection rate exceeded',
            self::SUBSCRIPTION_IDENTIFIERS_NOT_SUPPORTED => 'Subscription identifiers not supported',
            self::WILDCARD_SUBSCRIPTIONS_NOT_SUPPORTED => 'Wildcard subscriptions not supported',
            default => 'Unknown (0x'.dechex($code).')',
        };
    }
}
