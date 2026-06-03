<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebTransport;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;
use Kode\Messaging\Exception\WebTransportException;
use Kode\Messaging\Message\Message as Msg;

/**
 * WebTransport 会话（HTTP/2-fallback 传输）
 *
> 注：浏览器原生 WebTransport 需要 HTTP/3 终结点。
> 本实现提供基于 HTTP/2 扩展或 WebSocket 的 fallback，
> 使得业务可以在 HTTP/3 不可用的环境下使用 WebTransport-like API。
 *
> 协议（fallback）：CONNECT + 自定义头部
 *  - 双向流：子协议 `wt-bidi`
 *  - 单向流：子协议 `wt-unidi`
 *  - Datagram：子协议 `wt-dgram`（由 WebSocket 二进制帧承载）
 */
class WebTransportConnection extends WebSocketConnection
{
    /** @var callable(string $payload, array $meta): void */
    protected $onBidirectional = null;
    /** @var callable(string $payload, array $meta): void */
    protected $onUnidirectional = null;
    /** @var callable(string $payload, array $meta): void */
    protected $onDatagram = null;
    /** @var list<string> 待发单向流队列 */
    protected array $outboundUnidi = [];

    public function onBidirectional(callable $cb): void
    {
        $this->onBidirectional = $cb;
    }

    public function onUnidirectional(callable $cb): void
    {
        $this->onUnidirectional = $cb;
    }

    public function onDatagram(callable $cb): void
    {
        $this->onDatagram = $cb;
    }

    public function sendBidirectional(string $payload): bool
    {
        return $this->send($payload, ['binary' => true, 'wt.frame' => 'bidi']);
    }

    public function sendUnidirectional(string $payload): bool
    {
        return $this->send($payload, ['binary' => true, 'wt.frame' => 'unidi']);
    }

    public function sendDatagram(string $payload, bool $reliable = false): bool
    {
        $encoded = WebTransportCodec::encodeDatagram($payload, $reliable);
        return $this->send($encoded, ['binary' => true, 'wt.frame' => 'dgram']);
    }

    public function dispatchFrame(array $frame, array $context = []): void
    {
        $kind = (string)($context['wt.frame'] ?? '');
        $payload = $frame['payload'] ?? '';
        $msg = Msg::of(
            $payload,
            'webtransport',
            context: [
                'connection_id'  => $this->connId,
                'remote_address' => $this->remoteAddress,
                'wt_frame'       => $kind,
            ],
        );
        $handler = match ($kind) {
            'bidi'  => $this->onBidirectional,
            'unidi' => $this->onUnidirectional,
            'dgram' => $this->onDatagram,
            default => null,
        };
        if ($handler !== null) {
            try {
                $handler($payload, $msg->context());
            } catch (\Throwable) {
            }
        }
    }
}
