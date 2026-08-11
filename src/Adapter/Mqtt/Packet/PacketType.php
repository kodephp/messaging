<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt\Packet;

/**
 * MQTT 控制包类型（MQTT 3.1.1 / 5.0）
 */
final class PacketType
{
    public const CONNECT = 1;
    public const CONNACK = 2;
    public const PUBLISH = 3;
    public const PUBACK = 4;
    public const PUBREC = 5;
    public const PUBREL = 6;
    public const PUBCOMP = 7;
    public const SUBSCRIBE = 8;
    public const SUBACK = 9;
    public const UNSUBSCRIBE = 10;
    public const UNSUBACK = 11;
    public const PINGREQ = 12;
    public const PINGRESP = 13;
    public const DISCONNECT = 14;
    public const AUTH = 15; // MQTT 5.0
}
