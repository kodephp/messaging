<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 中间件接口（洋葱圈）
 *
 * 顺序：A → B → C → handler → C → B → A
 */
interface MiddlewareInterface
{
    /**
     * 处理消息；可调用 $next 传递到下游。
     *
     * @param callable(MessageInterface): MessageInterface $next
     */
    public function process(MessageInterface $message, callable $next): MessageInterface;
}
