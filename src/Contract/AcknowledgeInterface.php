<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

use Throwable;

/**
 * 订阅消息的确认/否认句柄（用于 QoS 1/2）。
 */
interface AcknowledgeInterface
{
    public function ack(): void;

    public function nack(?Throwable $reason = null): void;
}
