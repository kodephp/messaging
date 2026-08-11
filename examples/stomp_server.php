<?php declare(strict_types=1);

/**
 * STOMP 嵌入式 Broker 启动示例
 *
 * 启动后，业务可使用 stomp://0.0.0.0:61613 协议发布 / 订阅消息。
 *
 * 适用：本地开发、单元测试、嵌入式场景。
 * 生产环境推荐使用 RabbitMQ / ActiveMQ Artemis。
 *
 * 运行：php examples/stomp_server.php
 */

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('stomp://0.0.0.0:61613')
    ->on('message.received', function ($conn, $message): void {
        $destination = $message->topic();
        $body = $message->payload();
        fwrite(STDOUT, "[STOMP] destination={$destination} body={$body}\n");
    })
    ->start();
