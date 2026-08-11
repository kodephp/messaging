<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Stomp;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;
use Throwable;

/**
 * STOMP 客户端连接
 */
class StompConnection extends WebSocketConnection
{
    /** @var array<string, callable(array{headers: array<string, string>, body: string}): void> */
    protected array $destHandlers = [];

    /** @var array<string, callable(array{headers: array<string, string>, body: string}): void> */
    protected array $subHandlers = [];

    public function addDestinationHandler(string $destination, callable $handler): void
    {
        $this->destHandlers[$destination] = $handler;
    }

    public function addSubscriptionHandler(string $subId, callable $handler): void
    {
        $this->subHandlers[$subId] = $handler;
    }

    /**
     * 派发一条 MESSAGE 帧。
     */
    public function dispatchMessage(array $headers, string $body): void
    {
        $destination = (string) ($headers['destination'] ?? '');
        $subscription = (string) ($headers['subscription'] ?? '');
        $messageId = (string) ($headers['message-id'] ?? '');

        $msg = \Kode\Messaging\Message\Message::of(
            $body,
            'stomp',
            topic: $destination,
            context: [
                'connection_id' => $this->connId,
                'remote_address' => $this->remoteAddress,
                'destination' => $destination,
                'subscription' => $subscription,
                'message_id' => $messageId,
                'headers' => $headers,
            ],
        );

        // 优先按 subscription 派发
        if ($subscription !== '' && isset($this->subHandlers[$subscription])) {
            try {
                ($this->subHandlers[$subscription])(['headers' => $headers, 'body' => $body, 'message' => $msg]);
            } catch (Throwable) {
            }

            return;
        }
        // 其次按 destination 派发
        if ($destination !== '' && isset($this->destHandlers[$destination])) {
            try {
                ($this->destHandlers[$destination])(['headers' => $headers, 'body' => $body, 'message' => $msg]);
            } catch (Throwable) {
            }
        }
    }
}
