<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

use Kode\Messaging\Contract\AcknowledgeInterface;
use Throwable;

/**
 * 简单确认句柄（仅在内存总线中使用，外部驱动通常由底层系统处理 ack）。
 */
final class SimpleAck implements AcknowledgeInterface
{
    private bool $acknowledged = false;

    public function ack(): void
    {
        $this->acknowledged = true;
    }

    public function nack(?Throwable $reason = null): void
    {
        $this->acknowledged = false;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }
}
