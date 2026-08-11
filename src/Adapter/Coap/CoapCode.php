<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

/**
 * CoAP 响应码（RFC 7252 §5.2）
 *
 * 格式：CD.DD，其中：
 *  - 1.xx = Success
 *  - 2.xx = Success
 *  - 4.xx = Client Error
 *  - 5.xx = Server Error
 *
 * 常用码：
 *  - 0.01 GET / 0.02 POST / 0.03 PUT / 0.04 DELETE
 *  - 2.01 Created / 2.02 Deleted / 2.03 Valid / 2.04 Changed / 2.05 Content
 *  - 4.00 Bad Request / 4.01 Unauthorized / 4.04 Not Found / 4.05 Method Not Allowed
 *  - 5.00 Internal Server Error / 5.01 Not Implemented / 5.02 Bad Gateway / 5.04 Gateway Timeout
 */
final class CoapCode
{
    // 请求方法（class 0）
    public const GET = 0.01;
    public const POST = 0.02;
    public const PUT = 0.03;
    public const DELETE = 0.04;

    // Success（class 2）
    public const CREATED = 2.01;
    public const DELETED = 2.02;
    public const VALID = 2.03;
    public const CHANGED = 2.04;
    public const CONTENT = 2.05;
    public const CONTINUE = 2.31;  // RFC 7959 block-wise

    // Client Error（class 4）
    public const BAD_REQUEST = 4.00;
    public const UNAUTHORIZED = 4.01;
    public const BAD_OPTION = 4.02;
    public const FORBIDDEN = 4.03;
    public const NOT_FOUND = 4.04;
    public const METHOD_NOT_ALLOWED = 4.05;
    public const NOT_ACCEPTABLE = 4.06;
    public const REQUEST_ENTITY_TOO_LARGE = 4.13;
    public const UNSUPPORTED_CONTENT_FORMAT = 4.15;

    // Server Error（class 5）
    public const INTERNAL_SERVER_ERROR = 5.00;
    public const NOT_IMPLEMENTED = 5.01;
    public const BAD_GATEWAY = 5.02;
    public const SERVICE_UNAVAILABLE = 5.03;
    public const GATEWAY_TIMEOUT = 5.04;

    /**
     * 编码为单字节（class.detail）：
     *   class = (code >> 5) & 0x07
     *   detail = code & 0x1F
     */
    public static function encode(float $code): int
    {
        $class = (int) floor($code) & 0x07;
        $detail = (int) round(($code - floor($code)) * 100) & 0x1F;

        return ($class << 5) | $detail;
    }

    /**
     * 解码单字节到 float 码。
     */
    public static function decode(int $byte): float
    {
        $class = ($byte >> 5) & 0x07;
        $detail = $byte & 0x1F;

        return (float) ($class.'.'.str_pad((string) $detail, 2, '0', STR_PAD_LEFT));
    }

    public static function name(float $code): string
    {
        $str = number_format($code, 2);

        return match ($code) {
            self::GET, self::POST, self::PUT, self::DELETE => "{$str} (Request)",
            self::CREATED => "{$str} Created",
            self::DELETED => "{$str} Deleted",
            self::VALID => "{$str} Valid",
            self::CHANGED => "{$str} Changed",
            self::CONTENT => "{$str} Content",
            self::CONTINUE => "{$str} Continue",
            self::BAD_REQUEST => "{$str} Bad Request",
            self::UNAUTHORIZED => "{$str} Unauthorized",
            self::BAD_OPTION => "{$str} Bad Option",
            self::FORBIDDEN => "{$str} Forbidden",
            self::NOT_FOUND => "{$str} Not Found",
            self::METHOD_NOT_ALLOWED => "{$str} Method Not Allowed",
            self::NOT_ACCEPTABLE => "{$str} Not Acceptable",
            self::INTERNAL_SERVER_ERROR => "{$str} Internal Server Error",
            self::NOT_IMPLEMENTED => "{$str} Not Implemented",
            self::BAD_GATEWAY => "{$str} Bad Gateway",
            self::SERVICE_UNAVAILABLE => "{$str} Service Unavailable",
            default => "{$str}",
        };
    }
}
