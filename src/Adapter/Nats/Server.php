<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Nats;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\NatsException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;

/**
 * NATS 简易服务端（嵌入式 Broker）
 *
 * 适用：
 *  - 本地开发 / 单元测试
 *  - 边缘 / 嵌入式场景
 *
 * 支持：
 *  - INFO 握手
 *  - CONNECT
 *  - PUB / MSG（基于 subject 通配符 * / > 路由）
 *  - SUB / UNSUB（按 sid 维护订阅）
 *  - PING / PONG
 *
 * 不支持（生产 Broker 应使用 nats-server）：
 *  - 集群 / 路由
 *  - 持久化
 *  - 鉴权 / TLS
 */
final class Server extends AbstractAdapter
{
    /** @var resource|null */
    private $socket = null;
    /** @var array<string, NatsConnection> peer → connection */
    private array $connections = [];
    /** @var array<int, array{conn: NatsConnection, subject: string, queueGroup: ?string}> sid → 订阅 */
    private array $subscriptions = [];
    /** @var array<string, array<string, NatsConnection>> subject-pattern → peer → connection */
    private array $subIndex = [];
    /** @var array<string, string> peer → 待解析的输入缓冲 */
    private array $buffers = [];
    private int $nextSid = 1;
    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'nats';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_payload'  => 1_048_576,
            'ping_interval' => 30,
            'server_name'  => 'kode-nats',
        ];
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
            throw NatsException::serverError("listen 失败: {$errstr}");
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("NATS listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        $maxPayload = (int)($this->config['max_payload'] ?? 1_048_576);

        while ($this->running) {
            // 接受新连接
            $new = @stream_socket_accept($this->socket, 0);
            if ($new !== false) {
                $peer = stream_socket_get_name($new, true) ?: 'unknown';
                $this->connections[$peer] = new NatsConnection(
                    NatsConnection::generateId('nats'),
                    'nats',
                    $peer,
                    $new,
                );
                $this->buffers[$peer] = '';
                // 发送 INFO
                @fwrite($new, NatsCodec::encodeInfo([
                    'server_id'   => 'kode-' . bin2hex(random_bytes(4)),
                    'server_name' => $this->config['server_name'],
                    'version'     => '1.0.0',
                    'go'          => 'kode-php',
                    'host'        => '0.0.0.0',
                    'port'        => 0,
                    'max_payload' => $maxPayload,
                    'proto'       => 1,
                ]));
                $this->builder?->emit('connection.open', ['connection' => $this->connections[$peer]]);
            }

            // 读取已有连接
            foreach ($this->connections as $peer => $conn) {
                $sock = $conn->stream();
                if (!is_resource($sock)) {
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
        throw new \LogicException('NATS Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->buffers = [];
        $this->subscriptions = [];
        $this->subIndex = [];
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('nats', self::class);
    }

    /**
     * 解析并派发某 peer 缓冲区内的命令。
     */
    private function parseAndDispatch(string $peer): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn === null) {
            return;
        }
        $sock = $conn->stream();
        if (!is_resource($sock)) {
            return;
        }
        $buf = &$this->buffers[$peer];

        while (strlen($buf) > 0) {
            $head = strtoupper(strtok($buf, " \r\n"));
            if ($head === 'PUB') {
                $parsed = NatsCodec::parseWithPayload($buf);
                if ($parsed === null) {
                    return;
                }
                $buf = substr($buf, $parsed['parsed']);
                $this->dispatch($conn, $parsed['command']['op'], $parsed['command']['args'], $parsed['command']['payload']);
                continue;
            }
            $crlf = strpos($buf, NatsCodec::CRLF);
            if ($crlf === false) {
                return;
            }
            $line = substr($buf, 0, $crlf);
            $buf = substr($buf, $crlf + 2);
            if ($line === '') {
                continue;
            }
            $parts = explode(' ', $line, 2);
            $op = strtoupper($parts[0]);
            $args = isset($parts[1]) ? explode(' ', $parts[1]) : [];
            $this->dispatch($conn, $op, $args, '');
        }
    }

    private function dispatch(NatsConnection $conn, string $op, array $args, string $payload): void
    {
        $sock = $conn->stream();
        if (!is_resource($sock)) {
            return;
        }
        switch ($op) {
            case 'CONNECT':
                @fwrite($sock, NatsCodec::encodeOk());
                break;
            case 'PUB':
                $this->handlePublish($conn, $args, $payload);
                break;
            case 'SUB':
                $this->handleSub($conn, $args);
                break;
            case 'UNSUB':
                $this->handleUnsub($conn, $args);
                break;
            case 'PING':
                @fwrite($sock, NatsCodec::encodePong());
                break;
            case 'PONG':
                break;
            default:
                @fwrite($sock, NatsCodec::encodeErr("未知操作: {$op}"));
        }
    }

    private function handlePublish(NatsConnection $conn, array $args, string $payload): void
    {
        if ($args === []) {
            return;
        }
        $subject = (string)$args[0];
        $replyTo = null;
        // 第二个参数可能是 reply-to（若非纯数字）或 #bytes
        if (isset($args[1]) && !ctype_digit((string)$args[1])) {
            $replyTo = (string)$args[1];
        }
        $msg = Msg::fromRaw(
            $payload,
            'nats',
            topic: $subject,
            context: [
                'connection_id'  => $conn->id(),
                'remote_address' => $conn->remoteAddress(),
                'reply_to'       => $replyTo,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);

        // 路由：按 subject 模式分发
        foreach ($this->subIndex as $pattern => $subs) {
            if ($this->matchSubject($pattern, $subject)) {
                foreach ($subs as $subConn) {
                    $sid = $this->findSidForConn($subConn, $pattern);
                    if ($sid === null) {
                        continue;
                    }
                    $subSock = $subConn->stream();
                    if (is_resource($subSock)) {
                        @fwrite($subSock, NatsCodec::encodeMsg($subject, $sid, $payload, $replyTo));
                    }
                }
            }
        }
    }

    private function handleSub(NatsConnection $conn, array $args): void
    {
        if ($args === []) {
            return;
        }
        $subject = (string)$args[0];
        $sid = 0;
        $queueGroup = null;
        if (count($args) === 1) {
            $sid = ++$this->nextSid;
        } elseif (count($args) === 2) {
            $sid = (int)$args[1];
        } else {
            $queueGroup = (string)$args[1];
            $sid = (int)$args[2];
        }
        $this->subIndex[$subject][$conn->id()] = $conn;
        $this->subscriptions[$sid] = ['conn' => $conn, 'subject' => $subject, 'queueGroup' => $queueGroup];
    }

    private function handleUnsub(NatsConnection $conn, array $args): void
    {
        if ($args === []) {
            return;
        }
        $sid = (int)$args[0];
        $sub = $this->subscriptions[$sid] ?? null;
        if ($sub === null) {
            return;
        }
        unset($this->subIndex[$sub['subject']][$conn->id()]);
        if (empty($this->subIndex[$sub['subject']])) {
            unset($this->subIndex[$sub['subject']]);
        }
        unset($this->subscriptions[$sid]);
    }

    private function findSidForConn(NatsConnection $conn, string $pattern): ?int
    {
        foreach ($this->subscriptions as $sid => $sub) {
            if ($sub['conn'] === $conn && $sub['subject'] === $pattern) {
                return $sid;
            }
        }
        return null;
    }

    private function matchSubject(string $filter, string $subject): bool
    {
        if ($filter === $subject || $filter === '>') {
            return true;
        }
        $f = explode('.', $filter);
        $s = explode('.', $subject);
        $i = 0;
        while ($i < count($f) && $i < count($s)) {
            if ($f[$i] === '>') {
                return true;
            }
            if ($f[$i] === '*') {
                $i++;
                continue;
            }
            if ($f[$i] !== $s[$i]) {
                return false;
            }
            $i++;
        }
        return count($f) === count($s);
    }
}
