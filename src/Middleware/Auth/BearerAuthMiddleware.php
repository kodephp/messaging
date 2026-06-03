<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\Auth;

use Kode\Messaging\Contract\AuthenticatorInterface;
use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Exception\MessagingException;

/**
 * Bearer Token 鉴权中间件
 *
 * 从消息头 `Authorization: Bearer xxx` 中提取 Token 并校验。
 *
 * 业务方需提供：
 *  - 一个 callable 验证 Token 并返回 bool / userId
 *  或
 *  - 一个 AuthenticatorInterface 实例
 */
final class BearerAuthMiddleware implements MiddlewareInterface
{
    /** @var callable(string): bool|string */
    private $validator;

    /**
     * @param callable(string): bool|string|AuthenticatorInterface $validator
     */
    public function __construct(callable|AuthenticatorInterface $validator)
    {
        if ($validator instanceof AuthenticatorInterface) {
            $this->validator = fn(string $token) => $validator->authenticate(['token' => $token])->userId;
        } else {
            $this->validator = $validator;
        }
    }

    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $token = $this->extractToken($message);
        if ($token === null) {
            throw new MessagingException('缺少 Authorization Bearer Token', 4001);
        }
        $result = ($this->validator)($token);
        if ($result === false || $result === '' || $result === null) {
            throw new MessagingException('Bearer Token 校验失败', 4001);
        }
        return $next($message);
    }

    private function extractToken(MessageInterface $m): ?string
    {
        $headers = $m->headers();
        foreach (['authorization', 'Authorization', 'AUTHORIZATION'] as $key) {
            if (isset($headers[$key])) {
                $value = $headers[$key];
                if (preg_match('/^Bearer\s+(.+)$/i', $value, $m_) === 1) {
                    return trim($m_[1]);
                }
            }
        }
        // 也支持 query / subprotocol 携带
        if (isset($headers['sec-websocket-protocol'])) {
            return $headers['sec-websocket-protocol'];
        }
        return null;
    }
}
