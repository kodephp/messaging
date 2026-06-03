<?php

/**
 * Long-Polling 服务端示例
 *
 * 业务：
 *  - 客户端 GET /notifications?topic=xxx 长连接等待
 *  - 业务可主动 push（这里用文件模拟：写入 /tmp/lp-push-xxx 触发响应）
 *  - hold 超时（默认 25s）返回 204 No Content
 *
 * 启动：
 *  php examples/longpolling_server.php
 *
 * 测试：
 *  curl "http://127.0.0.1:8083/ping"
 *  curl "http://127.0.0.1:8083/notifications?topic=orders"   # 阻塞 25s
 *  echo '{"data":"hi"}' > /tmp/lp-push-orders               # 触发立即响应
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$builder = Messaging::server('poll://0.0.0.0:8083')
    ->on('connection.open', function ($conn) {
        echo "[open] {$conn->id()} from {$conn->remoteAddress()}\n";
    })
    ->on('message.received', function ($conn, $message) {
        $topic = $message->topic() ?? 'default';
        echo "[recv] {$conn->id()} topic={$topic} payload=" . substr((string)$message->payload(), 0, 80) . "\n";

        // 模拟业务：监听 /tmp/lp-push-{topic} 文件
        $file = "/tmp/lp-push-{$topic}";
        if (file_exists($file)) {
            $data = file_get_contents($file);
            @unlink($file);
            $conn->send(['topic' => $topic, 'data' => $data, 'ts' => time()]);
        } else {
            // 不立即响应 → 保持 hold
            // 真实业务场景：可由另一个协程 / 进程触发 $conn->send()
        }
    })
    ->on('connection.close', function ($conn) {
        echo "[close] {$conn->id()}\n";
    });

echo "Long-Polling server started at http://0.0.0.0:8083\n";
$builder->start();
