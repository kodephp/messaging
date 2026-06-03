<?php
/**
 * WebSocket RPC 示例
 *
 * 演示通过 WebSocket + Pub/Sub 实现请求-响应模式 RPC
 *
 * 协议：
 *  - 请求：{"id": "req-1", "method": "user.get", "params": {"id": 42}}
 *  - 响应：{"id": "req-1", "result": {...}}
 *  - 错误：{"id": "req-1", "error": {"code": 404, "message": "Not Found"}}
 *
 * 运行：
 *  - 启动服务端：php docs/examples/rpc.php server
 *  - 启动客户端：php docs/examples/rpc.php client
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Messaging\Messaging;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Contract\MessageInterface;

$mode = $argv[1] ?? 'server';

if ($mode === 'server') {
    // ===== 服务端：注册方法，分发请求 =====
    $methods = [
        'user.get' => function (array $params): array {
            $id = $params['id'] ?? 0;
            if ($id <= 0) {
                throw new RuntimeException('Invalid user id');
            }
            return [
                'id'   => $id,
                'name' => "User-{$id}",
                'time' => time(),
            ];
        },
        'system.ping' => fn() => ['pong' => microtime(true)],
    ];

    Messaging::server('ws://0.0.0.0:8080')
        ->on('message.received', function (ConnectionInterface $conn, MessageInterface $msg) use ($methods) {
            $payload = $msg->payload();
            if (!is_array($payload) || !isset($payload['id'], $payload['method'])) {
                return;
            }

            $id     = $payload['id'];
            $method = $payload['method'];
            $params = $payload['params'] ?? [];

            if (!isset($methods[$method])) {
                $conn->send([
                    'id'    => $id,
                    'error' => ['code' => -32601, 'message' => 'Method not found'],
                ]);
                return;
            }

            try {
                $result = $methods[$method]($params);
                $conn->send(['id' => $id, 'result' => $result]);
            } catch (\Throwable $e) {
                $conn->send([
                    'id'    => $id,
                    'error' => ['code' => -32000, 'message' => $e->getMessage()],
                ]);
            }
        })
        ->start();
} else {
    // ===== 客户端：发送请求，匹配响应 =====
    $client = Messaging::client('ws://localhost:8080')
        ->on('open', function () use (&$client) {
            echo "[client] connected\n";
            $client->send([
                'id'     => 'req-1',
                'method' => 'user.get',
                'params' => ['id' => 42],
            ]);
            $client->send(['id' => 'req-2', 'method' => 'system.ping']);
        })
        ->on('message', fn($m) => print_r($m->payload()))
        ->connect();

    $client->loop();
}
