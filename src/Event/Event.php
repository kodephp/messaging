<?php

declare(strict_types=1);

namespace Kode\Messaging\Event;

/**
 * 事件对象基类（PSR-14 StoppableEventInterface 替代）
 *
 * 提供 stopPropagation 机制，避免引入 PSR-14 接口的额外依赖。
 */
class Event
{
    public function __construct(
        public readonly string $name,
        public readonly array $payload = [],
    ) {
    }

    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
