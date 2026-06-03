<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\Codec;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;

/**
 * JSON 编解码中间件
 *
 *  入站：raw 字节 → JSON 解码为 array，写入 payload
 *  出站：payload 数组 → JSON 编码后写回 raw
 *
 *  默认只处理 text 帧；二进制帧跳过。
 */
final class JsonCodec implements MiddlewareInterface
{
    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $processed = $this->isBinaryLike($message)
            ? $message
            : $this->decode($message);
        $result = $next($processed);

        // 出站：把 payload 序列化回 raw
        if (is_array($result->payload()) || is_object($result->payload())) {
            $encoded = json_encode(
                $result->payload(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $result = $result->withPayload($encoded);
        }
        return $result;
    }

    private function isBinaryLike(MessageInterface $m): bool
    {
        return $m->isBinary();
    }

    private function decode(MessageInterface $m): MessageInterface
    {
        $raw = $m->raw();
        if ($raw === '') {
            return $m;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $m; // 非 JSON，保持原样
        }
        if (is_array($decoded) && isset($decoded['event']) && is_string($decoded['event'])) {
            return $m->withEvent($decoded['event'])->withPayload($decoded);
        }
        return $m->withPayload($decoded);
    }
}
