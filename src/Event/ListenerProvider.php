<?php

declare(strict_types=1);

namespace Kode\Messaging\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * 内置 ListenerProvider（实现 PSR-14）。
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function addListener(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        krsort($this->listeners[$event]);
    }

    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        foreach ($this->listeners as $registeredEvent => $byPriority) {
            if ($registeredEvent === $class || is_subclass_of($event, $registeredEvent)) {
                foreach ($byPriority as $listeners) {
                    foreach ($listeners as $listener) {
                        yield $listener;
                    }
                }
            }
        }
    }
}
