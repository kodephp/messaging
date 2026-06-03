<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

/**
 * CoAP 消息类型（RFC 7252 §4.2）
 */
final class CoapType
{
    public const CON = 0; // Confirmable
    public const NON = 1; // Non-confirmable
    public const ACK = 2; // Acknowledgement
    public const RST = 3; // Reset

    public static function name(int $type): string
    {
        return match ($type) {
            self::CON => 'CON',
            self::NON => 'NON',
            self::ACK => 'ACK',
            self::RST => 'RST',
            default   => "TYPE({$type})",
        };
    }
}
