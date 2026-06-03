<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

/**
 * 进程内 Pub/Sub 总线
 *
 * 适用：单进程；单机单 Worker。
 * 性能：极快（无 IPC 开销）。
 */
final class MemoryBus extends Bus
{
    public function driver(): string
    {
        return 'memory';
    }

    public function publish(string $topic, array $payload, array $options = []): void
    {
        $this->dispatch($topic, $payload);
    }

    protected function onSubscribe(string $topic, array $options): void
    {
        // 内存总线无需外部系统
    }

    protected function onUnsubscribe(string $topic): void
    {
        // 内存总线无需外部系统
    }
}
