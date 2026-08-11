<?php
/**
 * 极简 WebSocket 服务端示例
 *
 * 运行：php examples/websocket_server.php
 * 测试：浏览器打开 examples/websocket_client.html 或用 wscat 工具
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', fn($c) => $c->send('welcome'))
    ->on('message.received', fn($c, $m) => $c->send("echo: {$m->payload()}"))
    ->start();
