<?php

declare(strict_types=1);

namespace Kode\Messaging\Router\Match;

final class PrefixMatcher implements MatcherInterface
{
    public function __construct(private readonly string $pattern)
    {
    }

    public function match(string $subject): bool
    {
        // chat.*  → 匹配 chat.x，chat.x.y
        $pattern = str_replace('\\*', '.*', preg_quote($this->pattern, '#'));
        return (bool)preg_match('#^' . $pattern . '$#', $subject);
    }
}
