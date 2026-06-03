<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Adapter\WebSocket\Codec\Handshake;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\WebSocketException;
use Kode\Messaging\Server\Builder as ServerBuilder;
/**
 * WebSocket 服务端适配器（纯 PHP stream）
 *
 * 适用：教学、跨平台、零扩展依赖场景。
 * 生产环境推荐 Swoole/Swow 传输层（kode/process 协作）。
 */
final class Server extends AbstractAdapter
{
    /** @var resource|null */
    private $serverSocket = null;

    /** @var array<int, WebSocketConnection> */
    private array $connections = [];

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'ws';
    }

    public function version(): string
    {
        return 'rfc6455';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_frame_size'      => 1_048_576,
            'max_connections'     => 10_000,
            'allowed_origins'     => ['*'],
            'heartbeat_interval'  => 30,
            'heartbeat_timeout'   => 60,
            'handshake_timeout'   => 10,
            'subprotocols'        => [],
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create(['socket' => ['so_reuseaddr' => true, 'so_reuseport' => true]]);
        $this->serverSocket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx,
        );
        if ($this->serverSocket === false) {
            throw WebSocketException::handshakeFailed("无法监听 {$host}:{$port}: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_blocking($this->serverSocket, true);
        $this->logger->info("WebSocket listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        $maxConn = (int)($this->config['max_connections'] ?? 10_000);

        while ($this->running) {
            // 1. accept 新连接
            $new = @stream_socket_accept($this->serverSocket, 0);
            if ($new !== false) {
                if (count($this->connections) >= $maxConn) {
                    @fclose($new);
                    $this->logger->warning('WebSocket max connections reached, rejecting');
                } else {
                    $this->handshake($new);
                }
            }

            // 2. 读所有现有连接
            $this->readAll();

            // 3. 心跳
            $this->heartbeat();

            // 4. 让出 CPU
            usleep(1_000);
        }
    }

    private function handshake($socket): void
    {
        stream_set_timeout($socket, (int)($this->config['handshake_timeout'] ?? 10));
        $buf = '';
        $deadline = microtime(true) + (int)($this->config['handshake_timeout'] ?? 10);
        while (strpos($buf, "\r\n\r\n") === false && microtime(true) < $deadline) {
            $chunk = fread($socket, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        if (strpos($buf, "\r\n\r\n") === false) {
            @fclose($socket);
            return;
        }

        try {
            $response = Handshake::serverResponse($buf, [
                'allowed_origins' => $this->config['allowed_origins'] ?? ['*'],
                'subprotocols'    => $this->config['subprotocols'] ?? [],
            ]);
        } catch (WebSocketException $e) {
            $this->logger->warning('WebSocket handshake failed', ['error' => $e->getMessage()]);
            @fclose($socket);
            return;
        }

        @fwrite($socket, $response);

        $conn = new WebSocketConnection(
            WebSocketConnection::generateId('ws'),
            'ws',
            stream_socket_get_name($socket, true) ?: 'unknown',
            $socket,
        );
        $this->connections[(int)$socket] = $conn;
        $this->emit('connection.open', ['connection' => $conn]);
    }

    private function readAll(): void
    {
        $read = [];
        foreach ($this->connections as $sock => $conn) {
            if ($conn->isOpen()) {
                $read[] = $conn->stream();
            }
        }
        if ($read === []) {
            return;
        }
        $write = $except = null;
        $n = @stream_select($read, $write, $except, 0, 1_000);
        if ($n === false || $n === 0) {
            return;
        }
        foreach ($read as $stream) {
            $key = (int)$stream;
            $conn = $this->connections[$key] ?? null;
            if ($conn === null) {
                continue;
            }
            $frame = $conn->readOnce();
            if ($frame === null) {
                // 检查连接是否还活着
                $meta = @stream_get_meta_data($conn->stream());
                if ($meta === false || !empty($meta['timed_out'])) {
                    $this->removeConnection($conn);
                }
                continue;
            }
            $this->onFrame($conn, $frame);
        }
    }

    private function heartbeat(): void
    {
        $interval = (int)($this->config['heartbeat_interval'] ?? 30);
        if ($interval <= 0) {
            return;
        }
        $now = time();
        foreach ($this->connections as $key => $conn) {
            if (!$conn->isOpen()) {
                unset($this->connections[$key]);
                continue;
            }
            if ($now - $conn->lastPongAt() > $interval) {
                $conn->sendPing();
            }
        }
    }

    /**
     * 内部事件触发（通过 builder 派发）。
     */
    public function emit(string $event, array $payload = []): void
    {
        $this->builder?->emit($event, $payload);
    }

    public function onFrame(WebSocketConnection $conn, \Kode\Messaging\Adapter\WebSocket\Codec\Frame $frame): void
    {
        if ($frame->opcode === \Kode\Messaging\Adapter\WebSocket\Codec\OpCode::PING) {
            $conn->sendPong($frame->payload);
            return;
        }
        if ($frame->opcode === \Kode\Messaging\Adapter\WebSocket\Codec\OpCode::PONG) {
            $conn->markPong();
            return;
        }
        if ($frame->opcode === \Kode\Messaging\Adapter\WebSocket\Codec\OpCode::CLOSE) {
            $conn->close(1000, 'normal');
            return;
        }
        $isBinary = $frame->opcode === \Kode\Messaging\Adapter\WebSocket\Codec\OpCode::BINARY;
        $message = \Kode\Messaging\Message\Message::fromRaw(
            $frame->payload,
            'ws',
            headers: [],
            context: [
                'connection_id'   => $conn->id(),
                'remote_address'  => $conn->remoteAddress(),
            ],
        );
        if ($isBinary) {
            $message = new \Kode\Messaging\Message\Message(
                $message->id(), $message->event(), $message->topic(),
                $message->payload(), $message->raw(), $message->headers(),
                $message->qos(), true, $message->isRetain(), $message->protocol(),
                $message->timestamp(), $message->context(),
            );
        }
        $this->emit('message.received', ['connection' => $conn, 'message' => $message]);
    }

    public function removeConnection(WebSocketConnection $conn): void
    {
        foreach ($this->connections as $key => $c) {
            if ($c === $conn) {
                unset($this->connections[$key]);
                $this->emit('connection.close', ['connection' => $conn]);
                return;
            }
        }
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close(1001, 'server shutdown');
        }
        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public function connect(array $config = []): ConnectionInterface
    {
        throw new \LogicException('Server 适配器不支持 connect()');
    }

    public static function autoRegister(): void
    {
        Registry::register('ws', self::class);
    }
}
