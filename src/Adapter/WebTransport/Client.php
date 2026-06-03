<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebTransport;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\WebTransportException;

/**
 * WebTransport 客户端（HTTP/2-fallback 传输）
 *
> 注：浏览器原生 WebTransport 走 HTTP/3；
> 本 fallback 走 WebSocket，子协议：
>   - `wt-bidi`：双向流（普通 WS 消息）
>   - `wt-unidi`：单向流（服务端 → 客户端）
>   - `wt-dgram`：Datagram（WS 二进制帧）
 *
> 业务可同时启用原生 HTTP/3（接入 aioquic）与 fallback（WS），
> 通过 `Messaging::register('wt', NativeServer::class)` 切换。
 */
final class Client extends AbstractAdapter
{
    /** @var resource|null */
    private $stream = null;
    private ?WebTransportConnection $conn = null;

    public static function scheme(): string
    {
        return 'webtransport';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function defaultConfig(): array
    {
        return [
            'subprotocol' => 'wt-bidi',
            'host'        => '127.0.0.1',
            'port'        => 4433,
        ];
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 4433);
        $remote = "tcp://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT);
        if ($this->stream === false) {
            throw WebTransportException::handshakeFailed("无法连接 {$remote}: {$errstr}");
        }
        // 此处仅占位（实际 HTTP/3 握手由专用后端处理）
        $this->conn = new WebTransportConnection(
            WebTransportConnection::generateId('wt'),
            'webtransport',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
        );
        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new \LogicException('WebTransport Client 不支持 listen()');
    }

    public function run(): void
    {
        // HTTP/3 入口通常在外部进程中；
        // fallback 模式下业务可直接调用 sendBidirectional/sendDatagram。
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('webtransport', self::class);
    }
}
