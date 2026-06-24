<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

use Kode\Messaging\Exception\MqttException;

/**
 * MQTT 5.0 属性（Properties）编解码
 *
 * MQTT 5.0 在 CONNECT / CONNACK / PUBLISH / PUBACK / PUBREC / PUBREL / PUBCOMP /
 * SUBSCRIBE / SUBACK / UNSUBSCRIBE / UNSUBACK / DISCONNECT / AUTH 包中引入了
 * Properties 字段，用于传递丰富的元数据。
 *
 * 编码格式：先写剩余长度（变长整数），再依次写各属性。
 *
 * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html#_Toc3901027
 */
final class Properties
{
    // ===================== 属性 ID 常量 =====================

    /** Payload 格式指示（Byte） */
    public const PAYLOAD_FORMAT_INDICATOR = 1;

    /** 消息过期间隔，秒（4 Byte Integer） */
    public const MESSAGE_EXPIRY_INTERVAL = 2;

    /** 内容类型（UTF-8 String） */
    public const CONTENT_TYPE = 3;

    /** 响应主题（UTF-8 String）— 请求/响应模式 */
    public const RESPONSE_TOPIC = 8;

    /** 关联数据（Binary Data）— 请求/响应模式 */
    public const CORRELATION_DATA = 9;

    /** 订阅标识符（Variable Byte Integer）— 可重复 */
    public const SUBSCRIPTION_IDENTIFIER = 11;

    /** 会话过期间隔，秒（4 Byte Integer） */
    public const SESSION_EXPIRY_INTERVAL = 17;

    /** 服务端分配的客户端 ID（UTF-8 String） */
    public const ASSIGNED_CLIENT_IDENTIFIER = 18;

    /** 服务端 Keep Alive（2 Byte Integer） */
    public const SERVER_KEEP_ALIVE = 19;

    /** 认证方法（UTF-8 String） */
    public const AUTHENTICATION_METHOD = 21;

    /** 认证数据（Binary Data） */
    public const AUTHENTICATION_DATA = 22;

    /** 请求问题信息（Byte） */
    public const REQUEST_PROBLEM_INFORMATION = 23;

    /** 请求响应信息（Byte） */
    public const REQUEST_RESPONSE_INFORMATION = 25;

    /** 服务端引用（UTF-8 String） */
    public const SERVER_REFERENCE = 26;

    /** 原因字符串（UTF-8 String） */
    public const REASON_STRING = 28;

    /** 接收最大值（2 Byte Integer）— 流控 */
    public const RECEIVE_MAXIMUM = 31;

    /** 主题别名最大值（2 Byte Integer） */
    public const TOPIC_ALIAS_MAXIMUM = 33;

    /** 主题别名（2 Byte Integer） */
    public const TOPIC_ALIAS = 34;

    /** 最大 QoS（Byte） */
    public const MAXIMUM_QOS = 35;

    /** 保留可用（Byte） */
    public const RETAIN_AVAILABLE = 36;

    /** 用户属性（UTF-8 String Pair）— 可重复 */
    public const USER_PROPERTY = 37;

    /** 最大包大小（4 Byte Integer） */
    public const MAXIMUM_PACKET_SIZE = 38;

    /** 通配符订阅可用（Byte） */
    public const WILDCARD_SUBSCRIPTION_AVAILABLE = 39;

    /** 订阅标识符可用（Byte） */
    public const SUBSCRIPTION_IDENTIFIER_AVAILABLE = 40;

    /** 共享订阅可用（Byte） */
    public const SHARED_SUBSCRIPTION_AVAILABLE = 41;

    /**
     * 属性类型映射：属性 ID → 数据类型
     *
     * 用于编码时确定写入方式。
     */
    private const TYPE_MAP = [
        self::PAYLOAD_FORMAT_INDICATOR          => 'byte',
        self::MESSAGE_EXPIRY_INTERVAL           => 'uint32',
        self::CONTENT_TYPE                      => 'string',
        self::RESPONSE_TOPIC                    => 'string',
        self::CORRELATION_DATA                  => 'binary',
        self::SUBSCRIPTION_IDENTIFIER            => 'varint',
        self::SESSION_EXPIRY_INTERVAL           => 'uint32',
        self::ASSIGNED_CLIENT_IDENTIFIER        => 'string',
        self::SERVER_KEEP_ALIVE                 => 'uint16',
        self::AUTHENTICATION_METHOD             => 'string',
        self::AUTHENTICATION_DATA               => 'binary',
        self::REQUEST_PROBLEM_INFORMATION       => 'byte',
        self::REQUEST_RESPONSE_INFORMATION      => 'byte',
        self::SERVER_REFERENCE                  => 'string',
        self::REASON_STRING                     => 'string',
        self::RECEIVE_MAXIMUM                   => 'uint16',
        self::TOPIC_ALIAS_MAXIMUM               => 'uint16',
        self::TOPIC_ALIAS                       => 'uint16',
        self::MAXIMUM_QOS                       => 'byte',
        self::RETAIN_AVAILABLE                  => 'byte',
        self::USER_PROPERTY                     => 'string_pair',
        self::MAXIMUM_PACKET_SIZE               => 'uint32',
        self::WILDCARD_SUBSCRIPTION_AVAILABLE   => 'byte',
        self::SUBSCRIPTION_IDENTIFIER_AVAILABLE => 'byte',
        self::SHARED_SUBSCRIPTION_AVAILABLE     => 'byte',
    ];

    /**
     * 可重复的属性（同一包中可出现多次）。
     */
    private const REPEATABLE = [
        self::SUBSCRIPTION_IDENTIFIER,
        self::USER_PROPERTY,
    ];

    /**
     * 编码属性集合为字节流（含长度前缀）。
     *
     * @param array<int, mixed> $properties 属性 ID → 值
     *     - byte/uint16/uint32/varint: 标量
     *     - string/binary: string
     *     - string_pair (USER_PROPERTY): list<array{0: string, 1: string}>
     *     - 可重复属性 (SUBSCRIPTION_IDENTIFIER): list<int>
     *
     * @return string 含剩余长度前缀的完整 Properties 字段
     */
    public static function encode(array $properties = []): string
    {
        if ($properties === []) {
            return Codec::encodeRemainingLength(0);
        }

        $body = '';
        foreach ($properties as $id => $value) {
            $type = self::TYPE_MAP[$id] ?? null;
            if ($type === null) {
                throw MqttException::malformedPacket("未知属性 ID: {$id}");
            }

            if (in_array($id, self::REPEATABLE, true) && is_array($value)) {
                // 可重复属性
                if ($type === 'string_pair') {
                    foreach ($value as $pair) {
                        $body .= Codec::encodeRemainingLength($id);
                        $body .= Codec::encodeString((string)$pair[0]);
                        $body .= Codec::encodeString((string)$pair[1]);
                    }
                } else { // varint (SUBSCRIPTION_IDENTIFIER)
                    foreach ($value as $v) {
                        $body .= Codec::encodeRemainingLength($id);
                        $body .= Codec::encodeRemainingLength((int)$v);
                    }
                }
                continue;
            }

            $body .= Codec::encodeRemainingLength($id);
            $body .= match ($type) {
                'byte'        => Codec::encodeUint8((int)$value),
                'uint16'      => Codec::encodeUint16((int)$value),
                'uint32'      => pack('N', (int)$value),
                'varint'      => Codec::encodeRemainingLength((int)$value),
                'string'      => Codec::encodeString((string)$value),
                'binary'      => Codec::encodeBinary((string)$value),
                'string_pair' => Codec::encodeString((string)$value[0]) . Codec::encodeString((string)$value[1]),
            };
        }

        return Codec::encodeRemainingLength(strlen($body)) . $body;
    }

    /**
     * 解码 Properties 字段。
     *
     * @param string $data   原始数据
     * @param int    $offset 当前偏移（会被推进）
     *
     * @return array<int, mixed> 属性 ID → 值
     *     - 可重复属性以 list 形式返回
     *
     * @throws MqttException 解码失败
     */
    public static function decode(string $data, int &$offset): array
    {
        $propsLen = Codec::decodeRemainingLength($data, $offset);
        if ($propsLen === 0) {
            return [];
        }

        $end = $offset + $propsLen;
        if (strlen($data) < $end) {
            throw MqttException::malformedPacket('Properties 截断');
        }

        $result = [];
        while ($offset < $end) {
            $id = Codec::decodeRemainingLength($data, $offset);
            $type = self::TYPE_MAP[$id] ?? null;
            if ($type === null) {
                throw MqttException::malformedPacket("未知属性 ID: {$id}");
            }

            $value = match ($type) {
                'byte'        => Codec::decodeUint8($data, $offset),
                'uint16'      => Codec::decodeUint16($data, $offset),
                'uint32'      => self::decodeUint32($data, $offset),
                'varint'      => Codec::decodeRemainingLength($data, $offset),
                'string'      => Codec::decodeString($data, $offset),
                'binary'      => Codec::decodeBinary($data, $offset),
                'string_pair' => [Codec::decodeString($data, $offset), Codec::decodeString($data, $offset)],
            };

            // 可重复属性以 list 累积
            if (in_array($id, self::REPEATABLE, true)) {
                if (!isset($result[$id])) {
                    $result[$id] = [];
                }
                $result[$id][] = $value;
            } else {
                $result[$id] = $value;
            }
        }

        return $result;
    }

    /**
     * 解码 32-bit 无符号整数（大端序）。
     */
    private static function decodeUint32(string $data, int &$offset): int
    {
        if (strlen($data) < $offset + 4) {
            throw MqttException::malformedPacket('uint32 截断');
        }
        $v = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        return $v;
    }
}
