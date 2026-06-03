<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;

/**
 * 中间件管道
 *
 * 把一组中间件按注册顺序组成洋葱圈：
 *   A → B → C → handler → C → B → A
 */
final class Pipeline
{
    /** @var list<MiddlewareInterface|object{callable}|callable> */
    private array $middlewares = [];

    /**
     * 追加中间件。
     */
    public function push(MiddlewareInterface|callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 批量追加。
     *
     * @param iterable<MiddlewareInterface|callable> $middlewares
     */
    public function pushAll(iterable $middlewares): self
    {
        foreach ($middlewares as $mw) {
            $this->push($mw);
        }
        return $this;
    }

    /**
     * 透传处理：把消息依次经过所有中间件。
     */
    public function process(MessageInterface $message, callable $handler): MessageInterface
    {
        $next = $handler;
        // 倒序包裹
        foreach (array_reverse($this->middlewares) as $mw) {
            $current = $next;
            $next = function (MessageInterface $msg) use ($mw, $current): MessageInterface {
                if ($mw instanceof MiddlewareInterface) {
                    return $mw->process($msg, $current);
                }
                return $mw($msg, $current);
            };
        }
        return $next($message);
    }

    /**
     * 数量。
     */
    public function count(): int
    {
        return count($this->middlewares);
    }
}
