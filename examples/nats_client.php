<?php declare(strict_types=1);

/**
 * NATS 客户端发布 / 订阅示例
 *
 * 运行：php examples/nats_client.php
 *
 * 启动后，该客户端会：
 *  - 订阅 orders.*（任意后缀）
 *  - 每秒发布一条 orders.created 消息
 */

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$client = Messaging::client('nats://127.0.0.1:4222');

$client->subscribe('orders.*', function ($subject, $payload): void {
    fwrite(STDOUT, "[recv] {$subject} => {$payload}\n");
});

$client->connect();
fwrite(STDOUT, "[client] connected to nats://127.0.0.1:4222\n");

$i = 0;
while (true) {
    $payload = json_encode(['id' => ++$i, 'ts' => time()]);
    $client->publish('orders.created', $payload);
    fwrite(STDOUT, "[send] orders.created => {$payload}\n");
    sleep(1);
}
