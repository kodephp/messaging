<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\LongPolling;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Exception\LongPollingException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;
use LogicException;

/**
 * Long-Polling 服务端
 *
 * 工作流程：
 *  1. accept TCP 连接（HTTP 请求）
 *  2. 解析 HTTP 头 + body（POST 时）
 *  3. 提取 topic（query/path）
 *  4. 触发 message.received，业务可通过 setResponse() 主动响应
 *     或注册 interval 任务在数据到达时调用 respond()
 *  5. 到达 hold 超时则返回 204 No Content
 *
 * 用例：
 *   Messaging::server('poll://0.0.0.0:8083')
 *     ->on('message.received', function ($conn, $msg) {
 *         $conn->send(['echo' => $msg->payload()]);
 *     })
 *     ->start();
 */
final class Server extends AbstractAdapter
{
    /** @var null|resource */
    private $serverSocket = null;

    /** @var array<int, LongPollingConnection> 当前持有的连接 */
    private array $connections = [];

    /** @var array<string, array<int, LongPollingConnection>> topic → 该 topic 等待中的连接 */
    private array $topicIndex = [];

    private int $connSeq = 0;

    private ?ServerBuilder $builder = null;

    private ?Hub $hub = null;

    public static function scheme(): string
    {
        return 'long-polling';
    }

    public function version(): string
    {
        return 'http/1.1';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    public function setHub(Hub $hub): self
    {
        $this->hub = $hub;
        $hub->subscribe('*', function (mixed $payload): void {
            // 通用订阅（topic='*' 时通过 payload['topic'] 二次分发）
            if (is_array($payload) && isset($payload['__topic'])) {
                $this->deliver((string) $payload['__topic'], $payload['__payload'] ?? null);
            }
        });

        return $this;
    }

    public function hub(): ?Hub
    {
        return $this->hub;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_connections' => 10_000,
            'hold_timeout_ms' => 25_000,         // 单次 hold 最长
            'read_timeout' => 30,             // 读请求超时（秒）
            'max_body_size' => 1_048_576,      // 1 MiB
            'cors' => true,
            'allowed_origins' => ['*'],
            'ping' => false,          // GET / 探活
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->serverSocket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->serverSocket === false) {
            throw LongPollingException::listenFailed($host, $port, (string) $errstr);
        }
        $this->logger->info("Long-Polling listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        $maxConn = (int) ($this->config['max_connections'] ?? 10_000);
        $holdMs = (int) ($this->config['hold_timeout_ms'] ?? 25_000);
        $lastSweep = microtime(true);

        while ($this->running) {
            $new = @stream_socket_accept($this->serverSocket, 0);
            if ($new !== false) {
                if (count($this->connections) >= $maxConn) {
                    $this->sendError($new, 503, 'Service Unavailable');
                    @fclose($new);
                } else {
                    $this->handleRequest($new, $holdMs);
                }
            }

            // 定期扫描过期连接
            $now = microtime(true);
            if ($now - $lastSweep >= 1.0) {
                $this->sweepExpired($now, $holdMs);
                $lastSweep = $now;
            }

            // 避免 CPU 100%
            usleep(1_000);
        }
    }

    public function connect(array $config = []): \Kode\Messaging\Contract\ConnectionInterface
    {
        throw new LogicException('LongPolling Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->terminate();
        }
        $this->connections = [];
        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('long-polling', self::class);
    }

    /**
     * 解析并分发一个 HTTP 请求。
     */
    private function handleRequest($socket, int $holdMs): void
    {
        stream_set_timeout($socket, (int) ($this->config['read_timeout'] ?? 30));

        $request = $this->parseRequest($socket);
        if ($request === null) {
            @fclose($socket);

            return;
        }

        // 简单 ping
        if (! empty($this->config['ping']) && $request['method'] === 'GET' && $request['path'] === '/ping') {
            $this->writeResponse($socket, 200, 'OK', 'pong', ['Content-Type' => 'text/plain']);
            @fclose($socket);

            return;
        }

        // OPTIONS 预检直接 204
        if ($request['method'] === 'OPTIONS') {
            $this->writeResponse($socket, 204, 'No Content', '', $this->buildResponseHeaders($request['headers']['origin'] ?? null));
            @fclose($socket);

            return;
        }

        $peer = stream_socket_get_name($socket, true) ?: 'unknown';
        $topic = $request['query']['topic'] ?? null;
        $conn = new LongPollingConnection(
            \Kode\Messaging\Connection\Connection::generateId('lp'),
            (string) $peer,
            $socket,
            $this->buildResponseHeaders($request['headers']['origin'] ?? null),
        );
        // 记录开始时间与 hold 超时
        $conn->setAttribute('__opened_at', microtime(true));
        $conn->setAttribute('__hold_timeout_ms', $holdMs);

        $this->connections[++$this->connSeq] = $conn;
        $this->registerTopic($conn, $topic);

        $msg = Msg::fromRaw(
            $request['body'] ?? '',
            'long-polling',
            topic: $topic,
            context: [
                'connection_id' => $conn->id(),
                'method' => $request['method'],
                'path' => $request['path'],
                'query' => $request['query'],
                'headers' => $request['headers'],
                'remote_address' => $peer,
                'hold_timeout_ms' => $holdMs,
            ],
        );

        $this->builder?->emit('connection.open', ['connection' => $conn]);
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);
    }

    /**
     * 解析 HTTP 请求（最大 header 16 KiB、body 上限由配置决定）。
     *
     * @return null|array{method:string, path:string, query:array<string,string>, headers:array<string,string>, body:string}
     */
    private function parseRequest($socket): ?array
    {
        $headerBuf = '';
        $maxBody = (int) ($this->config['max_body_size'] ?? 1_048_576);
        $headerLimit = 16 * 1024;

        // 读 header（直到 \r\n\r\n）
        while (! feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                return null;
            }
            $headerBuf .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
            if (strlen($headerBuf) > $headerLimit) {
                $this->writeResponse($socket, 413, 'Payload Too Large', 'header too large');

                return null;
            }
        }

        $lines = explode("\r\n", trim($headerBuf));
        $startLine = array_shift($lines) ?: '';
        $parts = explode(' ', $startLine, 3);
        if (count($parts) < 3) {
            $this->writeResponse($socket, 400, 'Bad Request', 'invalid request line');

            return null;
        }
        [$method, $target, $_proto] = $parts;

        $headers = [];
        foreach ($lines as $h) {
            if ($h === '') {
                continue;
            }
            $pos = strpos($h, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($h, 0, $pos)));
            $val = trim(substr($h, $pos + 1));
            $headers[$key] = $val;
        }

        $path = $target;
        $query = [];
        $qPos = strpos($target, '?');
        if ($qPos !== false) {
            $path = substr($target, 0, $qPos);
            parse_str(substr($target, $qPos + 1), $query);
        }

        // 读 body
        $body = '';
        $contentLength = (int) ($headers['content-length'] ?? 0);
        if ($contentLength > 0) {
            if ($contentLength > $maxBody) {
                $this->writeResponse($socket, 413, 'Payload Too Large', 'body too large');

                return null;
            }
            $remaining = $contentLength;
            while ($remaining > 0 && ! feof($socket)) {
                $chunk = fread($socket, min($remaining, 8192));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
        }

        return [
            'method' => strtoupper($method),
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * 构造 HTTP 响应头（含 CORS）。
     *
     * @return array<string, string>
     */
    private function buildResponseHeaders(?string $origin): array
    {
        $h = [];
        if (! empty($this->config['cors'])) {
            $allowed = (array) ($this->config['allowed_origins'] ?? ['*']);
            $h['Access-Control-Allow-Origin'] = in_array('*', $allowed, true)
                ? '*'
                : ($allowed[array_search($origin, $allowed, true)] ?? ($allowed[0] ?? '*'));
            $h['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $h['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-Requested-With';
            $h['Access-Control-Max-Age'] = '86400';
        }

        return $h;
    }

    /**
     * 写一个完整响应并关闭 socket。
     */
    private function writeResponse($socket, int $code, string $text, string $body = '', array $extra = []): void
    {
        $headers = array_replace([
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Length' => (string) strlen($body),
            'Connection' => 'close',
        ], $extra);
        $packet = "HTTP/1.1 {$code} {$text}\r\n";
        foreach ($headers as $k => $v) {
            $packet .= "{$k}: {$v}\r\n";
        }
        $packet .= "\r\n{$body}";
        @fwrite($socket, $packet);
    }

    /**
     * 写一个错误响应。
     */
    private function sendError($socket, int $code, string $text): void
    {
        $body = json_encode(['error' => $text, 'code' => $code], JSON_UNESCAPED_UNICODE);
        $this->writeResponse($socket, $code, $text, (string) $body, ['Content-Type' => 'application/json']);
    }

    /**
     * 扫描过期 hold 连接。
     */
    private function sweepExpired(float $now, int $holdMs): void
    {
        foreach ($this->connections as $id => $conn) {
            $openedAt = (float) $conn->getAttribute('__opened_at', $now);
            $connHold = (int) $conn->getAttribute('__hold_timeout_ms', $holdMs);
            if ($now - $openedAt >= $connHold / 1000) {
                if (! $conn->hasResponded()) {
                    $conn->send('', ['status' => 204, 'status_text' => 'No Content']);
                }
                $conn->terminate();
                $this->unregisterTopic($conn);
                unset($this->connections[$id]);
                $this->builder?->emit('connection.close', ['connection' => $conn, 'reason' => 'hold_timeout']);
            }
        }
    }

    /**
     * 把连接按 topic 注册到 topic → conn 列表中。
     */
    private function registerTopic(LongPollingConnection $conn, ?string $topic): void
    {
        if ($topic === null || $topic === '') {
            return;
        }
        $this->topicIndex[$topic][(int) $conn->id()] = $conn;
        $conn->setAttribute('__topic', $topic);
    }

    private function unregisterTopic(LongPollingConnection $conn): void
    {
        $topic = (string) $conn->getAttribute('__topic', '');
        if ($topic === '') {
            return;
        }
        unset($this->topicIndex[$topic][(int) $conn->id()]);
        if (empty($this->topicIndex[$topic])) {
            unset($this->topicIndex[$topic]);
        }
    }

    /**
     * 把 payload 投递给所有订阅该 topic 的连接。
     *
     * 行为：
     *  - 每个连接仅响应一次
     *  - 默认 status=200，body 为 JSON 编码后的 payload
     */
    public function deliver(string $topic, mixed $payload): int
    {
        $count = 0;
        if (! isset($this->topicIndex[$topic])) {
            return 0;
        }
        foreach ($this->topicIndex[$topic] as $id => $conn) {
            if ($conn->hasResponded() || ! $conn->isOpen()) {
                unset($this->topicIndex[$topic][$id]);
                continue;
            }
            $body = is_string($payload) ? $payload : json_encode(
                ['topic' => $topic, 'data' => $payload, 'ts' => time()],
                JSON_UNESCAPED_UNICODE,
            );
            $conn->send($body, ['status' => 200, 'status_text' => 'OK']);
            unset($this->topicIndex[$topic][$id]);
            $count++;
        }
        if (empty($this->topicIndex[$topic])) {
            unset($this->topicIndex[$topic]);
        }

        return $count;
    }
}
