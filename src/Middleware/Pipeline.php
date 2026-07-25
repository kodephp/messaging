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
 *
 * 性能说明：中间件集合在 Builder 阶段固定，运行时（每条消息）只变化末端 handler。
 * 因此中间件洋葱链只编译一次（懒编译，首次 process() 时构建），
 * 后续 process() 调用仅切换末端 handler，避免每条消息重复创建 N 个闭包。
 * 调用 push()/pushAll() 变更中间件后会自动失效并重建。
 *
 * 注意：单条 process() 调用内部不应递归重入本管道（本项目无此用法）。
 */
final class Pipeline
{
    /** @var list<MiddlewareInterface|object{callable}|callable> */
    private array $middlewares = [];

    /** @var ?\Closure 已编译的洋葱链（首次 process() 构建，中间件不变则复用） */
    private ?\Closure $compiled = null;

    /** @var callable|null 当前 process() 调用的末端 handler */
    private $terminalHandler = null;

    /**
     * 追加中间件。
     */
    public function push(MiddlewareInterface|callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        $this->compiled = null;
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
     *
     * 中间件链懒编译：首次调用构建一次，之后复用；仅末端 handler 每次调用切换。
     */
    public function process(MessageInterface $message, callable $handler): MessageInterface
    {
        if ($this->compiled === null) {
            $next = function (MessageInterface $msg): MessageInterface {
                return ($this->terminalHandler)($msg);
            };
            foreach (array_reverse($this->middlewares) as $mw) {
                $current = $next;
                $next = function (MessageInterface $msg) use ($mw, $current): MessageInterface {
                    if ($mw instanceof MiddlewareInterface) {
                        return $mw->process($msg, $current);
                    }
                    return $mw($msg, $current);
                };
            }
            $this->compiled = $next;
        }
        $this->terminalHandler = $handler;
        return ($this->compiled)($message);
    }

    /**
     * 数量。
     */
    public function count(): int
    {
        return count($this->middlewares);
    }
}
