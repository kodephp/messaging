<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket;

/**
 * WebSocket 客户端连接（发送帧需 mask）
 */
final class ClientConnection extends WebSocketConnection
{
    protected function shouldMask(): bool
    {
        return true;
    }
}
