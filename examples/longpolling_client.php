<?php

/**
 * Long-Polling 客户端示例
 *
 * 用 POST + body 发起长轮询，每收到响应后立即发起下一次。
 *
 * 启动：
 *  php examples/longpolling_client.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Adapter\LongPolling\LongPollingClientConnection;
use Kode\Messaging\Messaging;

// 优雅退出
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () {
        echo "Stopping...\n";
        exit(0);
    });
}

$conn = Messaging::client('poll://127.0.0.1:8083/sync?topic=orders')
    ->connect();

if (!$conn instanceof LongPollingClientConnection) {
    fwrite(STDERR, "Unexpected connection type\n");
    exit(1);
}

// 设置请求方法与头
$conn->setMethod('POST');
$conn->setHeader('X-Token', 'demo-token');
$conn->setBody(json_encode(['since' => time() - 60]));

$conn->onMessage(function ($msg) {
    echo "[recv] " . json_encode($msg->payload(), JSON_UNESCAPED_UNICODE) . "\n";
});
$conn->onError(function (\Throwable $e) {
    echo "[error] {$e->getMessage()}\n";
});

echo "Long-Polling client started, polling...\n";
$conn->poll();
