<?php
/**
 * 极简 MQTT 订阅示例
 *
 * 运行：MQTT_HOST=127.0.0.1 php examples/mqtt_subscribe.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$broker = getenv('MQTT_HOST') ?: '127.0.0.1';

$sub = Messaging::client("mqtt://{$broker}:1883")
    ->withClientId('php-sub-' . bin2hex(random_bytes(4)))
    ->on('connect', function () use ($sub) {
        echo "[subscribe] connected\n";
        $sub->subscribe('demo/#', function ($topic, $payload, $message) {
            echo "[recv] {$topic} = {$payload} (qos={$message->qos()})\n";
        }, ['qos' => 1]);
    })
    ->connect();

$sub->loop();
