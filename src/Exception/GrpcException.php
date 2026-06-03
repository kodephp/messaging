<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * gRPC 协议异常
 *
 * 状态码区间：8201-8299
 * 参考：https://github.com/grpc/grpc/blob/master/doc/statuscodes.md
 */
class GrpcException extends MessagingException
{
    public static function connectFailed(string $reason, array $context = []): self
    {
        return new self("gRPC 连接失败: {$reason}", 8201, $context);
    }

    public static function http2Error(string $reason, array $context = []): self
    {
        return new self("gRPC HTTP/2 错误: {$reason}", 8202, $context);
    }

    public static function frameError(string $reason, array $context = []): self
    {
        return new self("gRPC 帧错误: {$reason}", 8203, $context);
    }

    public static function unavailable(string $reason, array $context = []): self
    {
        return new self("gRPC 服务不可用: {$reason}", 8204, $context);
    }

    /**
     * 构造 gRPC 状态码对应的异常。
     */
    public static function fromStatusCode(int $code, string $message = ''): self
    {
        $name = match ($code) {
            0  => 'OK',
            1  => 'CANCELLED',
            2  => 'UNKNOWN',
            3  => 'INVALID_ARGUMENT',
            4  => 'DEADLINE_EXCEEDED',
            5  => 'NOT_FOUND',
            6  => 'ALREADY_EXISTS',
            7  => 'PERMISSION_DENIED',
            8  => 'RESOURCE_EXHAUSTED',
            9  => 'FAILED_PRECONDITION',
            10 => 'ABORTED',
            11 => 'OUT_OF_RANGE',
            12 => 'UNIMPLEMENTED',
            13 => 'INTERNAL',
            14 => 'UNAVAILABLE',
            15 => 'DATA_LOSS',
            16 => 'UNAUTHENTICATED',
            default => 'STATUS_' . $code,
        };
        return new self("gRPC {$name}: {$message}", 8200 + $code, [
            'status_code' => $code,
            'status_name' => $name,
            'message'     => $message,
        ]);
    }
}
