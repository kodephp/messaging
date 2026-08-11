<?php

declare(strict_types=1);

namespace Kode\Messaging\Router\Match;

/**
 * 前缀 / 单级通配匹配器（chat.* → chat.x、chat.x.y）
 *
 * 正则在构造期编译一次；原实现每次 match() 都重跑 preg_quote + str_replace，
 * 在高频分发下会重复付出编译开销。
 */
final class PrefixMatcher implements MatcherInterface
{
    private readonly string $regex;

    public function __construct(private readonly string $pattern)
    {
        $this->regex = '#^'.str_replace('\\*', '.*', preg_quote($this->pattern, '#')).'$#';
    }

    public function match(string $subject): bool
    {
        return (bool) preg_match($this->regex, $subject);
    }

    /**
     * 原始 pattern（可观测 / 调试用）。
     */
    public function pattern(): string
    {
        return $this->pattern;
    }
}
