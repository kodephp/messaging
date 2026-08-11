<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\LongPolling;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use LogicException;

/**
 * Long-Polling 客户端
 *
 * 设计要点：
 *  - connect() 仅建立一次性的 HTTP 客户端连接（首请求）
 *  - 真正的"持续轮询"由 LongPollingClientConnection::poll() 完成
 *  - 业务可使用 withMethod / withBody / withHeader 配置请求
 *
 * 用法：
 *   $conn = Messaging::client('poll://api.example.com/sync?topic=orders')
 *     ->withMethod('POST')
 *     ->withHeader('X-Token', 'xxx')
 *     ->withBody(json_encode($req))
 *     ->onMessage(fn($msg) => print_r($msg->payload()))
 *     ->connect();
 *   $conn->poll();  // 启动持续轮询循环
 */
final class Client extends AbstractAdapter
{
    public static function scheme(): string
    {
        return 'long-polling';
    }

    public function version(): string
    {
        return 'http/1.1';
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 80);
        $path = $config['path'] ?? '/';
        $tls = (bool) ($config['tls'] ?? false);
        $query = $config['query'] ?? [];

        return new LongPollingClientConnection(
            LongPollingConnection::generateId('lp'),
            'long-polling',
            ($tls ? 'tls' : 'tcp')."://{$host}:{$port}",
            [
                'host' => $host,
                'port' => $port,
                'path' => $path,
                'query' => $query,
                'tls' => $tls,
                'method' => $config['method'] ?? 'GET',
                'body' => $config['body'] ?? '',
                'headers' => $config['headers'] ?? [],
                'read_timeout' => (int) ($config['read_timeout'] ?? 30),
                'retry_delay_ms' => (int) ($config['retry_delay_ms'] ?? 1_000),
                'max_retries' => (int) ($config['max_retries'] ?? 0),
            ],
            $this->logger,
        );
    }

    public function listen(string $host, int $port): void
    {
        throw new LogicException('LongPolling Client 不支持 listen()');
    }

    public function run(): void
    {
        // 客户端在 Builder::loop() 中由 Connection::poll() 驱动
    }

    public static function autoRegister(): void
    {
        Registry::register('long-polling', self::class);
    }
}
