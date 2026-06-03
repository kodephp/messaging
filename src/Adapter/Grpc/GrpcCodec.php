<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Grpc;

use Kode\Messaging\Exception\GrpcException;

/**
 * gRPC 帧（Length-Prefixed Message）编解码
 *
 * 帧格式（5 字节头）：
 *   uint8  compressed flag (1 = compressed, 0 = identity)
 *   uint32 message length (big-endian)
 *   bytes  payload
 *
 * 业务层 payload 通常是 protobuf 序列化结果，
 * 本适配器不强制使用 protobuf——业务可传任意二进制负载。
 */
final class GrpcCodec
{
    public const COMPRESSED = 0x01;
    public const IDENTITY   = 0x00;

    public const STATUS_OK                  = 0;
    public const STATUS_CANCELLED           = 1;
    public const STATUS_UNKNOWN             = 2;
    public const STATUS_INVALID_ARGUMENT    = 3;
    public const STATUS_DEADLINE_EXCEEDED   = 4;
    public const STATUS_NOT_FOUND           = 5;
    public const STATUS_ALREADY_EXISTS      = 6;
    public const STATUS_PERMISSION_DENIED   = 7;
    public const STATUS_RESOURCE_EXHAUSTED  = 8;
    public const STATUS_FAILED_PRECONDITION = 9;
    public const STATUS_ABORTED             = 10;
    public const STATUS_OUT_OF_RANGE        = 11;
    public const STATUS_UNIMPLEMENTED       = 12;
    public const STATUS_INTERNAL            = 13;
    public const STATUS_UNAVAILABLE         = 14;
    public const STATUS_DATA_LOSS           = 15;
    public const STATUS_UNAUTHENTICATED     = 16;

    /**
     * 编码 gRPC 帧。
     */
    public static function encode(string $payload, bool $compressed = false): string
    {
        return chr($compressed ? self::COMPRESSED : self::IDENTITY) . pack('N', strlen($payload)) . $payload;
    }

    /**
     * 解码 gRPC 帧（从 buffer 中取一帧）。
     *
     * @return array{compressed: bool, payload: string, consumed: int}|null
     */
    public static function decode(string $buffer, int $offset = 0): ?array
    {
        if (strlen($buffer) < $offset + 5) {
            return null;
        }
        $flag = ord($buffer[$offset]);
        $length = unpack('N', substr($buffer, $offset + 1, 4))[1];
        $payloadStart = $offset + 5;
        $payloadEnd = $payloadStart + $length;
        if (strlen($buffer) < $payloadEnd) {
            return null;
        }
        $payload = substr($buffer, $payloadStart, $length);
        return [
            'compressed' => (bool)($flag & self::COMPRESSED),
            'payload'    => $payload,
            'consumed'   => 5 + $length,
        ];
    }

    /**
     * 构造 Trailers-Only 响应（gRPC 状态码）。
     */
    public static function encodeTrailers(int $statusCode, string $message = ''): string
    {
        $trailers = "grpc-status: {$statusCode}\r\n";
        if ($message !== '') {
            $trailers .= "grpc-message: " . self::percentEncode($message) . "\r\n";
        }
        return $trailers;
    }

    /**
     * 解析 HTTP/2 trailers 中的 grpc-status。
     *
     * @param array<string, string> $headers
     */
    public static function parseStatus(array $headers): array
    {
        $status = (int)($headers['grpc-status'] ?? self::STATUS_OK);
        $message = $headers['grpc-message'] ?? '';
        if ($message !== '') {
            $message = self::percentDecode($message);
        }
        return ['status' => $status, 'message' => $message];
    }

    /**
     * gRPC 消息头：application/grpc+proto
     */
    public static function contentType(): string
    {
        return 'application/grpc';
    }

    private static function percentEncode(string $s): string
    {
        return rawurlencode($s);
    }

    private static function percentDecode(string $s): string
    {
        return rawurldecode($s);
    }
}
