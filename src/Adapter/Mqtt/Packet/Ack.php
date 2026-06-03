<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

use Kode\Messaging\Exception\MqttException;

/**
 * MQTT 通用响应包解析（CONNACK / SUBACK / PUBACK 等）
 */
final class Ack
{
    /**
     * 解析任意 ACK 包。
     *
     * @return array{type: int, packet_id?: int, reason_code?: int, session_present?: bool, return_code?: int}
     */
    public static function decode(int $expectedType, string $body): array
    {
        $offset = 0;
        $result = ['type' => $expectedType];

        if ($expectedType === PacketType::CONNACK) {
            // CONNACK: Acknowledge Flags (1) + Return Code (1)
            if (strlen($body) < 2) {
                throw MqttException::malformedPacket('CONNACK 截断');
            }
            $ackFlags = ord($body[0]);
            $result['session_present'] = ($ackFlags & 0x01) !== 0;
            $result['return_code'] = ord($body[1]);
            return $result;
        }

        if ($expectedType === PacketType::SUBACK) {
            $result['packet_id'] = Codec::decodeUint16($body, $offset);
            // 后面是若干 reason codes
            $result['reason_codes'] = [];
            while ($offset < strlen($body)) {
                $result['reason_codes'][] = Codec::decodeUint8($body, $offset);
            }
            return $result;
        }

        if (in_array($expectedType, [
            PacketType::PUBACK,
            PacketType::PUBREC,
            PacketType::PUBREL,
            PacketType::PUBCOMP,
            PacketType::UNSUBACK,
        ], true)) {
            $result['packet_id'] = Codec::decodeUint16($body, $offset);
            if ($offset < strlen($body)) {
                $result['reason_code'] = Codec::decodeUint8($body, $offset);
            }
            return $result;
        }

        return $result;
    }
}
