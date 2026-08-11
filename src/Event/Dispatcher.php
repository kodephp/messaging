<?php

declare(strict_types=1);

namespace Kode\Messaging\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * 轻量事件分发器（PSR-14 兼容）
 *
 * 当 kode/event 未安装时使用；安装后推荐改用 kode/event。
 */
final class Dispatcher implements EventDispatcherInterface
{
    public function __construct(private ?ListenerProviderInterface $provider = null) {}

    public function dispatch(object $event): object
    {
        if ($this->provider === null) {
            return $event;
        }
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
