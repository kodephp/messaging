<?php
/**
 * 极简 WebSocket 客户端示例
 *
 * 运行：php examples/websocket_client.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$client = Messaging::client('ws://echo.websocket.org')
    ->on('open',    function () use ($client) {
        echo "[open]\n";
        $client->send('hello from kode/messaging');
    })
    ->on('message', function ($m) { echo "[recv] " . $m->payload() . "\n"; })
    ->on('close',   function () { echo "[close]\n"; })
    ->connect();

$client->loop();
