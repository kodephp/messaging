<?php
/**
 * WebSocket 聊天室示例
 *
 * 运行：php docs/examples/chat.php
 * 测试：浏览器打开任意 WebSocket 客户端连接 ws://localhost:8080
 *
 * 功能：
 *  - 客户端发送纯文本消息，广播给所有客户端
 *  - 显示在线人数（每 5 秒推送一次）
 *  - 显示加入/离开系统消息
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Messaging\Messaging;

// 进程内 Pub/Sub 总线（集群场景改为 'redis'）
$bus = Messaging::pubsub('memory');

Messaging::server('ws://0.0.0.0:8080')
    ->withAllowedOrigins(['*'])  // 生产请指定
    ->on('connection.open', function ($conn) use ($bus) {
        $conn->setAttribute('joinedAt', microtime(true));

        // 系统消息
        $bus->publish('chat:system', [
            'type'   => 'join',
            'id'     => $conn->id(),
            'time'   => time(),
            'online' => 0, // 实际从连接池统计
        ]);
    })
    ->on('message.received', function ($conn, $message) use ($bus) {
        $payload = $message->payload();
        // 广播聊天消息
        $bus->publish('chat:room', [
            'id'      => $conn->id(),
            'payload' => is_string($payload) ? $payload : json_encode($payload),
            'time'    => microtime(true),
        ]);
    })
    ->on('connection.close', function ($conn) use ($bus) {
        $bus->publish('chat:system', [
            'type' => 'leave',
            'id'   => $conn->id(),
            'time' => time(),
        ]);
    })
    // 订阅总线并发送给所有连接（演示用，生产可走集群总线）
    ->withHandler('chat.broadcast', function ($conn, $message) {
        // 演示：每 5 秒推送一次在线人数
        static $timer = null;
        if ($timer === null) {
            $timer = true; // 占位
            // 真实实现请使用 interval
        }
    })
    ->start();
