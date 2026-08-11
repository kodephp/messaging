<?php declare(strict_types=1);

/**
 * NATS 嵌入式 Broker 启动示例
 *
 * 启动后，业务可使用 nats://0.0.0.0:4222 协议发布 / 订阅消息。
 *
 * 适用：本地开发、单元测试、边缘场景。
 * 生产环境推荐使用官方 nats-server。
 *
 * 运行：php examples/nats_server.php
 */

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('nats://0.0.0.0:4222')
    ->on('message.received', function ($conn, $message): void {
        $subject = $message->topic();
        $payload = $message->payload();
        fwrite(STDOUT, "[NATS] subject={$subject} payload={$payload}\n");
    })
    ->start();
