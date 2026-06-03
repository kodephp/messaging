<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

use Kode\Messaging\Exception\MqttException;

/**
 * MQTT CONNECT 包（3.1.1 / 5.0）
 */
final class Connect
{
    public static function encode(
        string $clientId,
        ?string $username = null,
        ?string $password = null,
        int $keepalive = 60,
        bool $cleanSession = true,
        ?array $will = null,
        string $version = '3.1.1',
    ): string {
        $protocolName = $version === '5.0' ? 'MQTT' : 'MQTT';
        $protocolLevel = $version === '5.0' ? 5 : 4;

        $varHeader = Codec::encodeString($protocolName);
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

        // MQTT 5.0 属性
        if ($version === '5.0') {
            $varHeader .= Codec::encodeRemainingLength(0); // 暂时不写 properties
        }

        $payload = Codec::encodeString($clientId);
        if ($will !== null) {
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
}
