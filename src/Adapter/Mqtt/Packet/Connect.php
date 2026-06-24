<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

/**
 * MQTT CONNECT 包（3.1.1 / 5.0）
 *
 * 5.0 新增：CONNECT Properties 字段（会话过期间隔、接收最大值、用户属性等）。
 */
final class Connect
{
    /**
     * 编码 CONNECT 包。
     *
     * @param string             $clientId      客户端 ID
     * @param string|null        $username      用户名（可选）
     * @param string|null        $password      密码（可选）
     * @param int                $keepalive     心跳间隔（秒）
     * @param bool               $cleanSession  Clean Session（3.1.1）/ Clean Start（5.0）
     * @param array|null         $will          遗嘱消息 {topic, payload, qos, retain}
     * @param string             $version       '3.1.1' 或 '5.0'
     * @param array<int, mixed>  $properties    MQTT 5.0 CONNECT 属性（仅 5.0 生效）
     *
     * @return string 完整 CONNECT 包字节流
     */
    public static function encode(
        string $clientId,
        ?string $username = null,
        ?string $password = null,
        int $keepalive = 60,
        bool $cleanSession = true,
        ?array $will = null,
        string $version = '3.1.1',
        array $properties = [],
    ): string {
        $protocolLevel = $version === '5.0' ? 5 : 4;

        $varHeader = Codec::encodeString('MQTT');
        $varHeader .= Codec::encodeUint8($protocolLevel);

        // Connect Flags
        $flags = 0;
        if ($cleanSession) {
            $flags |= 0x02;
        }
        if ($will !== null) {
            $flags |= 0x04; // Will Flag
            $flags |= match ($will['qos'] ?? 0) {
                1       => 0x08,
                2       => 0x10,
                default => 0x00,
            };
            if ($will['retain'] ?? false) {
                $flags |= 0x20;
            }
        }
        if ($password !== null) {
            $flags |= 0x40;
        }
        if ($username !== null) {
            $flags |= 0x80;
        }
        $varHeader .= Codec::encodeUint8($flags);
        $varHeader .= Codec::encodeUint16($keepalive);

        // MQTT 5.0: CONNECT Properties
        if ($protocolLevel === 5) {
            $varHeader .= Properties::encode($properties);
        }

        $payload = Codec::encodeString($clientId);
        if ($will !== null) {
            // MQTT 5.0: Will Properties（在 will topic 之前）
            if ($protocolLevel === 5) {
                $willProps = $will['properties'] ?? [];
                $payload .= Properties::encode($willProps);
            }
            $payload .= Codec::encodeString($will['topic'] ?? '');
            $payload .= Codec::encodeBinary((string)($will['payload'] ?? ''));
        }
        if ($username !== null) {
            $payload .= Codec::encodeString($username);
        }
        if ($password !== null) {
            $payload .= Codec::encodeString($password);
        }

        $body = $varHeader . $payload;
        return Codec::encodeFixedHeader(PacketType::CONNECT, 0, strlen($body)) . $body;
    }

    /**
     * 解码 CONNECT 包体。
     *
     * @param string $body 剩余长度后的包体
     *
     * @return array{
     *     protocol_name: string,
     *     protocol_level: int,
     *     clean_session: bool,
     *     will_flag: bool,
     *     will_qos: int,
     *     will_retain: bool,
     *     username_flag: bool,
     *     password_flag: bool,
     *     keepalive: int,
     *     client_id: string,
     *     will_topic: ?string,
     *     will_payload: ?string,
     *     username: ?string,
     *     password: ?string,
     *     properties: array<int, mixed>,
     *     will_properties: array<int, mixed>,
     *     version: string
     * }
     */
    public static function decode(string $body): array
    {
        $offset = 0;

        $protocolName = Codec::decodeString($body, $offset);
        $protocolLevel = Codec::decodeUint8($body, $offset);
        $flagsByte = Codec::decodeUint8($body, $offset);
        $keepalive = Codec::decodeUint16($body, $offset);

        $isV5 = $protocolLevel === 5;

        $cleanSession = ($flagsByte & 0x02) !== 0;
        $willFlag = ($flagsByte & 0x04) !== 0;
        $willQos = ($flagsByte >> 3) & 0x03;
        $willRetain = ($flagsByte & 0x20) !== 0;
        $passwordFlag = ($flagsByte & 0x40) !== 0;
        $usernameFlag = ($flagsByte & 0x80) !== 0;

        // MQTT 5.0: CONNECT Properties
        $properties = [];
        if ($isV5) {
            $properties = Properties::decode($body, $offset);
        }

        // Payload
        $clientId = Codec::decodeString($body, $offset);

        $willTopic = null;
        $willPayload = null;
        $willProperties = [];
        if ($willFlag) {
            // MQTT 5.0: Will Properties
            if ($isV5) {
                $willProperties = Properties::decode($body, $offset);
            }
            $willTopic = Codec::decodeString($body, $offset);
            $willPayload = Codec::decodeBinary($body, $offset);
        }

        $username = null;
        $password = null;
        if ($usernameFlag) {
            $username = Codec::decodeString($body, $offset);
        }
        if ($passwordFlag) {
            $password = Codec::decodeBinary($body, $offset);
        }

        return [
            'protocol_name'    => $protocolName,
            'protocol_level'   => $protocolLevel,
            'clean_session'    => $cleanSession,
            'will_flag'        => $willFlag,
            'will_qos'         => $willQos,
            'will_retain'      => $willRetain,
            'username_flag'    => $usernameFlag,
            'password_flag'    => $passwordFlag,
            'keepalive'        => $keepalive,
            'client_id'        => $clientId,
            'will_topic'       => $willTopic,
            'will_payload'     => $willPayload,
            'username'         => $username,
            'password'         => $password,
            'properties'       => $properties,
            'will_properties'  => $willProperties,
            'version'          => $isV5 ? '5.0' : '3.1.1',
        ];
    }
}
