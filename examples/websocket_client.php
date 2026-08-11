<?php
/**
 * 极简 WebSocket 客户端示例
 *
 * 运行：php examples/websocket_client.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$client = Messaging::client('ws://echo.websocket.org')
    ->on('open', function () use ($client): void {
        echo "[open]\n";
        $client->send('hello from kode/messaging');
    })
    ->on('message', function ($m): void {
        echo '[recv] '.$m->payload()."\n";
    })
    ->on('close', function (): void {
        echo "[close]\n";
    })
    ->connect();

$client->loop();
