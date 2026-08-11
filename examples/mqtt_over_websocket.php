<?php

/**
 * IoT 场景示例：MQTT + WebSocket 组合架构
 *
 * 场景：充电桩 / 手表等设备端用裸 MQTT，App / 网页管理后台用 MQTT over WebSocket。
 *
 * 架构：
 *
 *   充电桩 (MQTT Client) ──tcp:1883──┐
 *   手表   (MQTT Client) ──tcp:1883──┤
 *                                     ├──→ MQTT Broker ──→ App / 网页后台
 *   App    (MQTT over WS) ──ws:8083──┘    (kode/messaging)
 *
 * 运行方式：
 *   php examples/mqtt_over_websocket.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

// ============================================================
// 1. 启动 MQTT over WebSocket 服务端（端口 8083）
// ============================================================
// App / 网页管理后台通过 ws:// 连接，承载 MQTT 协议
$wsServer = Messaging::server('mqtt+ws://0.0.0.0:8083')
    ->on('connection.open', fn($conn) => print "[ws] 客户端连接: {$conn->remoteAddress()}\n")
    ->on('message.received', function ($conn, $msg): void {
        $topic = $msg->topic() ?? '(unknown)';
        $payload = is_string($msg->payload()) ? $msg->payload() : json_encode($msg->payload());
        echo "[ws] 收到消息 topic={$topic} payload={$payload}\n";
    })
    ->on('connection.close', fn($conn) => print "[ws] 客户端断开: {$conn->remoteAddress()}\n");

// ============================================================
// 2. 启动裸 MQTT 服务端（端口 1883）
// ============================================================
// 充电桩 / 手表等设备端通过 tcp:// 连接
$mqttServer = Messaging::server('mqtt://0.0.0.0:1883')
    ->on('connection.open', fn($conn) => print "[mqtt] 设备连接: {$conn->remoteAddress()}\n")
    ->on('message.received', function ($conn, $msg): void {
        $topic = $msg->topic() ?? '(unknown)';
        $payload = is_string($msg->payload()) ? $msg->payload() : json_encode($msg->payload());
        echo "[mqtt] 设备消息 topic={$topic} payload={$payload}\n";
    })
    ->on('connection.close', fn($conn) => print "[mqtt] 设备断开: {$conn->remoteAddress()}\n");

// ============================================================
// 3. 集群模式（可选）
// ============================================================
// 百万设备连接时，多节点通过 Redis 总线同步消息
// $wsServer->withCluster(true, 'node-1');
// $mqttServer->withCluster(true, 'node-1');

echo "====================================\n";
echo " MQTT + WebSocket 组合服务已启动\n";
echo "====================================\n";
echo " 设备端 (充电桩/手表):  mqtt://127.0.0.1:1883\n";
echo " 用户端 (App/网页):     ws://127.0.0.1:8083/mqtt\n";
echo "====================================\n";
echo " 按 Ctrl+C 停止\n\n";

// 启动服务（阻塞）
$mqttServer->start();
