<?php

declare(strict_types=1);

namespace Kode\Messaging\Router\Match;

final class RegexMatcher implements MatcherInterface
{
    public function __construct(private readonly string $pattern)
    {
    }

    public function match(string $subject): bool
    {
        return (bool)preg_match($this->pattern, $subject);
    }
}
