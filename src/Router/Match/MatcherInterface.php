<?php

declare(strict_types=1);

namespace Kode\Messaging\Router\Match;

interface MatcherInterface
{
    public function match(string $subject): bool;
}
