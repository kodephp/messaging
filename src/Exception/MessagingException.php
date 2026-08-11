<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

use RuntimeException;
use Throwable;

/**
 * Messaging 统一异常基类
 *
 * 所有 Messaging 子异常都继承自此类。业务层可通过 catch MessagingException
 * 统一捕获协议无关的异常；具体协议异常再细分（WebSocketException 等）。
 *
 * 状态码约定：
 *   - 4xx：业务可恢复错误（鉴权失败、限流、协议客户端错误）
 *   - 5xx：协议/传输层错误
 */
class MessagingException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context 错误上下文（用于日志/事件）
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取上下文。
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * 转换为数组，便于序列化到事件/日志。
     *
     * @return array{message: string, code: int, type: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'type' => static::class,
            'context' => $this->context,
        ];
    }
}
