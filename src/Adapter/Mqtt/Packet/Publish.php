<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

/**
 * MQTT PUBLISH 包
 */
final class Publish
{
    /**
     * 编码 PUBLISH 包。
     */
    public static function encode(
        string $topic,
        string $payload,
        int $qos = 0,
        bool $retain = false,
        bool $dup = false,
        int $packetId = 0,
    ): string {
        $flags = 0;
        if ($dup) {
            $flags |= 0x08;
        }
        $flags |= match ($qos) {
            1 => 0x02,
            2 => 0x04,
            default => 0x00,
        };
        if ($retain) {
            $flags |= 0x01;
        }

        $varHeader = Codec::encodeString($topic);
        if ($qos > 0) {
            $varHeader .= Codec::encodeUint16($packetId);
        }
        $body = $varHeader . $payload;
        return Codec::encodeFixedHeader(PacketType::PUBLISH, $flags, strlen($body)) . $body;
    }

    /**
     * 解码 PUBLISH 包，返回 topic / payload / flags。
     *
     * @return array{topic: string, payload: string, qos: int, retain: bool, dup: bool, packet_id: int, remaining: string}
     */
    public static function decode(string $fixedHeader, string $body): array
    {
        $byte0 = ord($fixedHeader[0]);
        $flags = $byte0 & 0x0F;
        $dup = ($flags & 0x08) !== 0;
        $qos = ($flags >> 1) & 0x03;
        $retain = ($flags & 0x01) !== 0;

        $offset = 0;
        $topic = Codec::decodeString($body, $offset);
        $packetId = 0;
        if ($qos > 0) {
            $packetId = Codec::decodeUint16($body, $offset);
        }
        $payload = substr($body, $offset);

        return [
            'topic'     => $topic,
            'payload'   => $payload,
            'qos'       => $qos,
            'retain'    => $retain,
            'dup'       => $dup,
            'packet_id' => $packetId,
            'remaining' => $payload,
        ];
    }
}
