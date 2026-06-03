<?php

declare(strict_types=1);

namespace Kode\Messaging\Router;

use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Router\Match\MatcherInterface;
use Kode\Messaging\Router\Match\PrefixMatcher;
use Kode\Messaging\Router\Match\RegexMatcher;

/**
 * 消息路由器
 *
 * 根据 event / topic 把消息分发到对应的 handler。
 * 支持：
 *  - 精确匹配
 *  - 前缀匹配 (chat.*)
 *  - 通配符 (chat.#)
 *  - 正则
 */
final class Router
{
    /** @var array<string, callable> 精确匹配 */
    private array $exact = [];
    /** @var array<string, callable> 前缀/通配符匹配 */
    private array $patterns = [];
    /** @var array<string, callable> 正则匹配 */
    private array $regex = [];

    public function on(string $pattern, callable $handler): self
    {
        if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
            $this->regex[$pattern] = $handler;
            return $this;
        }
        if (str_contains($pattern, '*') || str_contains($pattern, '#')) {
            $this->patterns[$pattern] = $handler;
            return $this;
        }
        $this->exact[$pattern] = $handler;
        return $this;
    }

    /**
     * 分发消息。
     */
    public function dispatch(ConnectionInterface $conn, MessageInterface $message): void
    {
        $key = $message->event() ?? $message->topic() ?? '';
        if ($key === '') {
            return;
        }

        // 1. 精确
        if (isset($this->exact[$key])) {
            $this->call($this->exact[$key], $conn, $message);
            return;
        }

        // 2. 通配符
        foreach ($this->patterns as $pattern => $handler) {
            $matcher = $this->buildMatcher($pattern);
            if ($matcher->match($key)) {
                $this->call($handler, $conn, $message);
                return;
            }
        }

        // 3. 正则
        foreach ($this->regex as $pattern => $handler) {
            if ((bool)preg_match($pattern, $key)) {
                $this->call($handler, $conn, $message);
                return;
            }
        }
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
        return '#^' . $regex . '$#';
    }

    private function call(callable $handler, ConnectionInterface $conn, MessageInterface $message): void
    {
        try {
            $handler($conn, $message);
        } catch (\Throwable $e) {
            // 静默或派发 error.middleware 事件
        }
    }

    public function count(): int
    {
        return count($this->exact) + count($this->patterns) + count($this->regex);
    }
}
