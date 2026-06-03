<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 事件订阅者接口（PSR-14 兼容）。
 */
interface EventSubscriberInterface
{
    /**
     * 返回事件名 → handler 的映射。
     *
     * @return array<string, callable>
     */
    public function subscribers(): array;
}
