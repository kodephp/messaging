<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Grpc;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;
use Throwable;

/**
 * gRPC 客户端连接
 */
class GrpcConnection extends WebSocketConnection
{
    /** @var array<int, callable(string, array): void> */
    protected array $streamHandlers = [];

    public function addStreamHandler(int $streamId, callable $handler): void
    {
        $this->streamHandlers[$streamId] = $handler;
    }

    public function dispatchFrame(array $frame): void
    {
        $payload = $frame['payload'];
        $msg = \Kode\Messaging\Message\Message::of(
            $payload,
            'grpc',
            context: [
                'connection_id' => $this->connId,
                'remote_address' => $this->remoteAddress,
                'compressed' => $frame['compressed'],
            ],
        );
        // 默认广播给 streamId=0 的 handler
        foreach ($this->streamHandlers as $handler) {
            try {
                $handler($payload, $msg->context());
            } catch (Throwable) {
            }
        }
    }
}
