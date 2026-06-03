<?php
/**
 * 极简 SSE 服务端示例
 *
 * 运行：php examples/sse_server.php
 * 测试：浏览器访问 http://localhost:8081
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('sse://0.0.0.0:8081')
    ->on('connection.open', fn($c) => $c->send([
        'event' => 'connected',
        'data'  => ['id' => $c->id()],
    ]))
    ->interval(1000)
    ->on('interval', fn($c) => $c->send([
        'event' => 'tick',
        'data'  => ['time' => time()],
    ]))
    ->start();
