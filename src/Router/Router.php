<?php

declare(strict_types=1);

namespace Kode\Messaging\Router;

use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Router\Match\MatcherInterface;
use Kode\Messaging\Router\Match\PrefixMatcher;
use Kode\Messaging\Router\Match\RegexMatcher;
use Throwable;

/**
 * 消息路由器
 *
 * 根据 event / topic 把消息分发到对应的 handler。
 * 支持：
 *  - 精确匹配
 *  - 前缀匹配 (chat.*)
 *  - 通配符 (chat.#)
 *  - 正则
 *
 * 性能说明：通配符 / 正则的 Matcher 在 on() 注册阶段编译一次并缓存。
 * 原实现每分发一条消息都会为每个 pattern 新建 Matcher 对象并重新编译正则，
 * 在「多路由 × 高频消息」下开销随消息量线性放大。
 */
final class Router
{
    /** @var array<string, callable> 精确匹配 */
    private array $exact = [];

    /** @var array<string, array{matcher: MatcherInterface, handler: callable}> 前缀/通配符匹配（Matcher 已编译） */
    private array $patterns = [];

    /** @var array<string, callable> 正则匹配 */
    private array $regex = [];

    /** @var null|callable 未命中任何路由时的兜底处理器 */
    private $fallbackHandler = null;

    /** @var null|callable handler 抛异常时的回调：fn(\Throwable, ConnectionInterface, MessageInterface): void */
    private $errorHandler = null;

    public function on(string $pattern, callable $handler): self
    {
        if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
            $this->regex[$pattern] = $handler;

            return $this;
        }
        if (str_contains($pattern, '*') || str_contains($pattern, '#')) {
            // 注册期编译一次，分发期直接复用
            $this->patterns[$pattern] = [
                'matcher' => $this->buildMatcher($pattern),
                'handler' => $handler,
            ];

            return $this;
        }
        $this->exact[$pattern] = $handler;

        return $this;
    }

    /**
     * 移除一条路由（按注册时的 pattern 原样传入）。
     */
    public function off(string $pattern): self
    {
        unset($this->exact[$pattern], $this->patterns[$pattern], $this->regex[$pattern]);

        return $this;
    }

    /**
     * 未命中任何路由时的兜底处理器（传 null 取消）。
     */
    public function fallback(?callable $handler): self
    {
        $this->fallbackHandler = $handler;

        return $this;
    }

    /**
     * handler 抛异常时的回调（传 null 恢复静默）。
     *
     * @param null|(callable(Throwable, ConnectionInterface, MessageInterface): void) $handler
     */
    public function onError(?callable $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * 是否已注册该 pattern。
     */
    public function has(string $pattern): bool
    {
        return isset($this->exact[$pattern])
            || isset($this->patterns[$pattern])
            || isset($this->regex[$pattern]);
    }

    /**
     * 已注册的 pattern 列表（精确 → 通配 → 正则）。
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return [
            ...array_keys($this->exact),
            ...array_keys($this->patterns),
            ...array_keys($this->regex),
        ];
    }

    /**
     * 分发消息。
     *
     * @return bool 是否命中了某条路由（兜底 handler 不计为命中）
     */
    public function dispatch(ConnectionInterface $conn, MessageInterface $message): bool
    {
        $key = $message->event() ?? $message->topic() ?? '';
        if ($key === '') {
            return false;
        }

        // 1. 精确
        if (isset($this->exact[$key])) {
            $this->call($this->exact[$key], $conn, $message);

            return true;
        }

        // 2. 通配符（Matcher 已在注册期编译）
        foreach ($this->patterns as $entry) {
            if ($entry['matcher']->match($key)) {
                $this->call($entry['handler'], $conn, $message);

                return true;
            }
        }

        // 3. 正则
        foreach ($this->regex as $pattern => $handler) {
            if ((bool) preg_match($pattern, $key)) {
                $this->call($handler, $conn, $message);

                return true;
            }
        }

        // 4. 兜底
        if ($this->fallbackHandler !== null) {
            $this->call($this->fallbackHandler, $conn, $message);
        }

        return false;
    }

    private function buildMatcher(string $pattern): MatcherInterface
    {
        return str_contains($pattern, '#')
            ? new RegexMatcher($this->patternToRegex($pattern))
            : new PrefixMatcher($pattern);
    }

    private function patternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $regex = str_replace(['\\*', '\\#'], ['[^/]+', '.*'], $escaped);

        return '#^'.$regex.'$#';
    }

    private function call(callable $handler, ConnectionInterface $conn, MessageInterface $message): void
    {
        try {
            $handler($conn, $message);
        } catch (Throwable $e) {
            if ($this->errorHandler !== null) {
                ($this->errorHandler)($e, $conn, $message);
            }
            // 未注册 onError 时保持静默，避免单个 handler 异常拖垮整条连接
        }
    }

    public function count(): int
    {
        return count($this->exact) + count($this->patterns) + count($this->regex);
    }
}
