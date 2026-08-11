<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Sse;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Exception\SseException;
use Kode\Messaging\Server\Builder as ServerBuilder;
use LogicException;
use Throwable;

/**
 * SSE 服务端
 *
 * 工作流程：
 *  1. accept TCP 连接
 *  2. 验证 HTTP 请求（GET + Accept: text/event-stream）
 *  3. 写入 HTTP/1.1 200 + SSE 响应头
 *  4. 把连接加入连接池
 *  5. 在事件循环中：
 *     - 读取客户端断开（实现 heartbeat）
 *     - 触发 interval 事件（业务推送）
 */
final class Server extends AbstractAdapter
{
    /** @var null|resource */
    private $serverSocket = null;

    /** @var array<int, SseConnection> */
    private array $connections = [];

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'sse';
    }

    public function version(): string
    {
        return 'html5';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'retry_ms' => 3000,
            'keepalive_seconds' => 15,
            'max_connections' => 10_000,
            'heartbeat_event' => 'ping',
            'enable_cors' => true,
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
            throw SseException::invalidEvent("无法监听 {$host}:{$port}: {$errstr}", ['host' => $host, 'port' => $port]);
        }
        $this->logger->info("SSE listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        $maxConn = (int) ($this->config['max_connections'] ?? 10_000);
        $lastInterval = 0;
        $intervals = (array) ($this->builder?->intervals() ?? []);

        while ($this->running) {
            $new = @stream_socket_accept($this->serverSocket, 0);
            if ($new !== false) {
                if (count($this->connections) >= $maxConn) {
                    @fclose($new);
                } else {
                    $this->acceptHttp($new);
                }
            }

            $this->checkAlive();

            // 触发 interval 事件
            $now = (int) (microtime(true) * 1000);
            foreach ($intervals as $msStr => $handler) {
                $ms = (int) $msStr;
                if ($now - $lastInterval < $ms) {
                    continue;
                }
                foreach ($this->connections as $conn) {
                    if ($conn->isOpen()) {
                        try {
                            $handler($conn);
                        } catch (Throwable $e) {
                            $this->logger->error('interval handler error', ['error' => $e->getMessage()]);
                        }
                    }
                }
                $lastInterval = $now;
            }

            usleep(1_000);
        }
    }

    private function acceptHttp($socket): void
    {
        stream_set_timeout($socket, 5);
        $buf = '';
        while (! str_contains($buf, "\r\n\r\n")) {
            $chunk = @fread($socket, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            if (strlen($buf) > 8192) {
                break;
            }
        }
        $firstLine = strtok($buf, "\r\n");
        if (! is_string($firstLine) || ! preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]#', $firstLine, $m)) {
            @fclose($socket);

            return;
        }
        $path = $m[1];

        $headers = [];
        foreach (explode("\r\n", $buf) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        $accept = $headers['accept'] ?? '';
        if (! str_contains($accept, 'text/event-stream')) {
            @fwrite($socket, "HTTP/1.1 406 Not Acceptable\r\nContent-Length: 0\r\n\r\n");
            @fclose($socket);

            return;
        }

        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/event-stream; charset=utf-8\r\n";
        $response .= "Cache-Control: no-cache\r\n";
        $response .= "Connection: keep-alive\r\n";
        if ($this->config['enable_cors'] ?? true) {
            $origin = $headers['origin'] ?? '*';
            $response .= "Access-Control-Allow-Origin: {$origin}\r\n";
        }
        $response .= "X-Accel-Buffering: no\r\n"; // 禁用 Nginx 缓冲
        $retry = (int) ($this->config['retry_ms'] ?? 3000);
        $response .= "retry: {$retry}\r\n";
        $response .= "\r\n";
        @fwrite($socket, $response);

        $conn = new SseConnection(
            SseConnection::generateId('sse'),
            'sse',
            stream_socket_get_name($socket, true) ?: 'unknown',
            $socket,
        );
        $conn->setAttribute('path', $path);
        $this->connections[(int) $socket] = $conn;
        $this->builder?->emit('connection.open', ['connection' => $conn]);
    }

    private function checkAlive(): void
    {
        $keepalive = (int) ($this->config['keepalive_seconds'] ?? 15);
        if ($keepalive <= 0) {
            return;
        }
        $now = time();
        foreach ($this->connections as $key => $conn) {
            $meta = @stream_get_meta_data($conn->stream());
            if (! empty($meta['timed_out'])) {
                $this->removeConnection($conn);
                continue;
            }
            // 发送 keepalive 注释
            if ($now - (int) $conn->getAttribute('last_keepalive', $now) > $keepalive) {
                @fwrite($conn->stream(), ": keepalive\n\n");
                @fflush($conn->stream());
                $conn->setAttribute('last_keepalive', $now);
            }
        }
    }

    public function removeConnection(SseConnection $conn): void
    {
        foreach ($this->connections as $key => $c) {
            if ($c === $conn) {
                unset($this->connections[$key]);
                $this->builder?->emit('connection.close', ['connection' => $conn]);

                return;
            }
        }
    }

    public function connect(array $config = []): \Kode\Messaging\Contract\ConnectionInterface
    {
        throw new LogicException('SSE Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close(1000, 'server shutdown');
        }
        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('sse', self::class);
    }
}
