<?php
/**
 * SSE 实时推送示例
 *
 * 运行：php docs/examples/push.php
 * 测试：浏览器访问 http://localhost:8081
 *
 * 场景：
 *  - 客户端连接后立即收到欢迎消息
 *  - 每 1 秒推送 tick 事件（时间戳）
 *  - 每 5 秒推送一次随机通知
 *  - 业务侧可通过发布订阅总线触发即时推送
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Messaging\Messaging;
use Kode\Messaging\Contract\ConnectionInterface;

// 收集所有连接用于总线推送
$connections = new \WeakMap();

Messaging::server('sse://0.0.0.0:8081')
    ->withAllowedOrigins(['*'])
    ->on('connection.open', function (ConnectionInterface $conn) use (&$connections) {
        $connections[$conn] = true;
        $conn->send([
            'event' => 'connected',
            'id'    => $conn->id(),
            'time'  => time(),
        ]);
    })
    ->on('connection.close', function (ConnectionInterface $conn) use (&$connections) {
        unset($connections[$conn]);
    })
    ->interval(1000) // 每秒
    ->on('interval', function (ConnectionInterface $conn) {
        $conn->send([
            'event' => 'tick',
            'data'  => ['time' => microtime(true)],
        ]);
    })
    ->interval(5000)
    ->on('interval', function (ConnectionInterface $conn) {
        $conn->send([
            'event' => 'notice',
            'data'  => [
                'id'    => bin2hex(random_bytes(4)),
                'title' => '系统通知',
                'body'  => '这是一条每 5 秒推送一次的随机通知',
            ],
        ]);
    })
    ->start();
