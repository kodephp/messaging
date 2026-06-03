<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

/**
 * CoAP 选项编号（RFC 7252 §5.10 / RFC 7959 / RFC 8613）
 *
 * Option Delta 三段式编码（4 / 8 / 12 bit 扩展）
 */
final class CoapOption
{
    // Core (RFC 7252)
    public const IF_MATCH       = 1;
    public const URI_HOST       = 3;
    public const ETAG           = 4;
    public const IF_NONE_MATCH  = 5;
    public const URI_PORT       = 7;
    public const LOCATION_PATH  = 8;
    public const URI_PATH       = 11;
    public const CONTENT_FORMAT = 12;
    public const MAX_AGE        = 14;
    public const URI_QUERY      = 15;
    public const ACCEPT         = 17;
    public const LOCATION_QUERY = 20;
    public const PROXY_URI      = 35;
    public const PROXY_SCHEME   = 39;
    public const SIZE1          = 60;

    // Content-Formats
    public const FMT_TEXT       = 0;   // text/plain;charset=utf-8
    public const FMT_LINK       = 40;  // application/link-format
    public const FMT_XML        = 41;  // application/xml
    public const FMT_OCTET      = 42;  // application/octet-stream
    public const FMT_EXI        = 47;  // application/exi
    public const FMT_JSON       = 50;  // application/json
    public const FMT_CBOR       = 60;  // application/cbor

    // Block-wise transfers (RFC 7959)
    public const BLOCK1 = 27;
    public const BLOCK2 = 31;
    public const SIZE2  = 28;     // expected response size

    // Observe (RFC 7641)
    public const OBSERVE       = 6;

    public static function name(int $number): string
    {
        return match ($number) {
            self::IF_MATCH       => 'If-Match',
            self::URI_HOST       => 'Uri-Host',
            self::ETAG           => 'ETag',
            self::IF_NONE_MATCH  => 'If-None-Match',
            self::URI_PORT       => 'Uri-Port',
            self::LOCATION_PATH  => 'Location-Path',
            self::URI_PATH       => 'Uri-Path',
            self::CONTENT_FORMAT => 'Content-Format',
            self::MAX_AGE        => 'Max-Age',
            self::URI_QUERY      => 'Uri-Query',
            self::ACCEPT         => 'Accept',
            self::LOCATION_QUERY => 'Location-Query',
            self::PROXY_URI      => 'Proxy-Uri',
            self::PROXY_SCHEME   => 'Proxy-Scheme',
            self::SIZE1          => 'Size1',
            self::BLOCK1         => 'Block1',
            self::BLOCK2         => 'Block2',
            self::SIZE2          => 'Size2',
            self::OBSERVE        => 'Observe',
            default              => "Opt({$number})",
        };
    }
}
