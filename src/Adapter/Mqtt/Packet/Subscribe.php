<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

/**
 * MQTT SUBSCRIBE 包
 */
final class Subscribe
{
    public static function encode(int $packetId, array $topics): string
    {
        // topics: [['topic' => 'a/b', 'qos' => 0], ...]
        $varHeader = Codec::encodeUint16($packetId);
        $payload = '';
        foreach ($topics as $t) {
            $payload .= Codec::encodeString((string) $t['topic']);
            $payload .= Codec::encodeUint8((int) ($t['qos'] ?? 0));
        }
        $body = $varHeader.$payload;

        return Codec::encodeFixedHeader(PacketType::SUBSCRIBE, 0x02, strlen($body)).$body;
    }
}
