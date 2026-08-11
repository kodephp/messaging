<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Stomp;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\StompException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;
use LogicException;

/**
 * STOMP 简易服务端
 *
 * 适用：本地开发 / 单元测试 / 嵌入式场景。
 * 生产环境推荐使用 RabbitMQ / ActiveMQ Artemis。
 */
final class Server extends AbstractAdapter
{
    /** @var null|resource */
    private $socket = null;

    /** @var array<string, StompConnection> peer → connection */
    private array $connections = [];

    /** @var array<string, string> peer → 解析缓冲 */
    private array $buffers = [];

    /** @var array<string, array<string, StompConnection>> destination → peer → connection */
    private array $subIndex = [];

    /** @var array<string, string> peer → session-id（CONNECTED 回包时生成） */
    private array $sessions = [];

    private int $nextMessageId = 1;

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'stomp';
    }

    public function version(): string
    {
        return '1.2';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->socket === false) {
            throw StompException::serverError("listen 失败: {$errstr}");
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("STOMP listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            $new = @stream_socket_accept($this->socket, 0);
            if ($new !== false) {
                $peer = stream_socket_get_name($new, true) ?: 'unknown';
                $this->connections[$peer] = new StompConnection(
                    StompConnection::generateId('stomp'),
                    'stomp',
                    $peer,
                    $new,
                );
                $this->buffers[$peer] = '';
                $this->builder?->emit('connection.open', ['connection' => $this->connections[$peer]]);
            }

            foreach ($this->connections as $peer => $conn) {
                $sock = $conn->stream();
                if (! is_resource($sock)) {
                    continue;
                }
                $chunk = @fread($sock, 4096);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $this->buffers[$peer] .= $chunk;
                $this->parseAndDispatch($peer);
            }

            usleep(1_000);
        }
    }

    public function connect(array $config): ConnectionInterface
    {
        throw new LogicException('STOMP Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->buffers = [];
        $this->subIndex = [];
        $this->sessions = [];
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('stomp', self::class);
    }

    /**
     * 获取已连接 peer 的 session-id。
     */
    public function getSession(string $peer): ?string
    {
        return $this->sessions[$peer] ?? null;
    }

    /**
     * 主动推送消息到 destination 的所有订阅者。
     */
    public function pushTo(string $destination, string $body, array $headers = []): int
    {
        $count = 0;
        if (! isset($this->subIndex[$destination])) {
            return 0;
        }
        foreach ($this->subIndex[$destination] as $subConn) {
            $subId = $this->findSubIdForConn($subConn, $destination);
            if ($subId === null) {
                continue;
            }
            $messageId = 'msg-'.$this->nextMessageId++;
            $sock = $subConn->stream();
            if (is_resource($sock)) {
                @fwrite($sock, StompCodec::encodeMessage($destination, $messageId, $subId, $body, $headers));
                $count++;
            }
        }

        return $count;
    }

    private function parseAndDispatch(string $peer): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn === null) {
            return;
        }
        $sock = $conn->stream();
        if (! is_resource($sock)) {
            return;
        }
        $buf = &$this->buffers[$peer];

        while (($nullPos = strpos($buf, StompCodec::NULL)) !== false) {
            $frame = StompCodec::decodeFrame($buf, 0);
            if ($frame === null) {
                return;
            }
            $buf = substr($buf, $frame['consumed']);
            $this->dispatch($conn, $frame);
        }
    }

    private function dispatch(StompConnection $conn, array $frame): void
    {
        $sock = $conn->stream();
        if (! is_resource($sock)) {
            return;
        }
        $command = $frame['command'];
        $headers = $frame['headers'];
        $body = $frame['body'];
        $peer = $conn->remoteAddress();

        switch ($command) {
            case StompCodec::COMMAND_CONNECT:
            case StompCodec::COMMAND_STOMP:
                $this->handleConnect($conn, $headers);
                break;
            case StompCodec::COMMAND_SEND:
                $this->handleSend($conn, $headers, $body);
                break;
            case StompCodec::COMMAND_SUBSCRIBE:
                $this->handleSubscribe($conn, $headers);
                break;
            case StompCodec::COMMAND_UNSUBSCRIBE:
                $this->handleUnsubscribe($conn, $headers);
                break;
            case StompCodec::COMMAND_DISCONNECT:
                @fwrite($sock, StompCodec::encodeFrame(StompCodec::COMMAND_RECEIPT, ['receipt-id' => 'disc']));
                $conn->close(0, 'disconnect');
                unset($this->connections[$peer]);
                unset($this->buffers[$peer]);
                $this->builder?->emit('connection.close', ['connection' => $conn, 'reason' => 'stomp.disconnect']);
                break;
            case StompCodec::COMMAND_ACK:
            case StompCodec::COMMAND_NACK:
                // 业务可选
                break;
        }
    }

    private function handleConnect(StompConnection $conn, array $headers): void
    {
        $sock = $conn->stream();
        if (! is_resource($sock)) {
            return;
        }
        $session = 'kode-'.bin2hex(random_bytes(4));
        $this->sessions[$conn->remoteAddress()] = $session;
        $conn->setAttribute('stomp.session', $session);
        @fwrite($sock, StompCodec::encodeConnected([
            'version' => '1.2',
            'session' => $session,
            'server' => 'kode-messaging/1.0',
        ]));
    }

    private function handleSend(StompConnection $conn, array $headers, string $body): void
    {
        $destination = (string) ($headers['destination'] ?? '');
        if ($destination === '') {
            $sock = $conn->stream();
            if (is_resource($sock)) {
                @fwrite($sock, StompCodec::encodeError('缺少 destination'));
            }

            return;
        }
        $msg = Msg::fromRaw(
            $body,
            'stomp',
            topic: $destination,
            context: [
                'connection_id' => $conn->id(),
                'remote_address' => $conn->remoteAddress(),
                'destination' => $destination,
                'headers' => $headers,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);

        // 转发到所有订阅者
        $this->pushTo($destination, $body, array_diff_key($headers, array_flip(['destination'])));
    }

    private function handleSubscribe(StompConnection $conn, array $headers): void
    {
        $subId = (string) ($headers['id'] ?? '');
        $destination = (string) ($headers['destination'] ?? '');
        if ($subId === '' || $destination === '') {
            $sock = $conn->stream();
            if (is_resource($sock)) {
                @fwrite($sock, StompCodec::encodeError('缺少 id 或 destination'));
            }

            return;
        }
        $this->subIndex[$destination][$conn->id()] = $conn;
        $conn->setAttribute('stomp.sub.'.$subId, $destination);
    }

    private function handleUnsubscribe(StompConnection $conn, array $headers): void
    {
        $subId = (string) ($headers['id'] ?? '');
        $destination = (string) $conn->getAttribute('stomp.sub.'.$subId, '');
        if ($destination === '') {
            return;
        }
        unset($this->subIndex[$destination][$conn->id()]);
        if (empty($this->subIndex[$destination])) {
            unset($this->subIndex[$destination]);
        }
        $conn->setAttribute('stomp.sub.'.$subId, null);
    }

    private function findSubIdForConn(StompConnection $conn, string $destination): ?string
    {
        foreach ($conn->attributes() as $k => $v) {
            if (str_starts_with($k, 'stomp.sub.') && $v === $destination) {
                return substr($k, strlen('stomp.sub.'));
            }
        }

        return null;
    }
}
