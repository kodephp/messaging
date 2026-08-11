<?php
/**
 * 极简 MQTT 发布示例
 *
 * 运行：MQTT_HOST=127.0.0.1 php examples/mqtt_publish.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Messaging;

$broker = getenv('MQTT_HOST') ?: '127.0.0.1';

$pub = Messaging::client("mqtt://{$broker}:1883")
    ->withClientId('php-pub-'.bin2hex(random_bytes(4)))
    ->on('connect', function () use ($pub): void {
        echo "[publish] connected\n";
        for ($i = 1; $i <= 5; $i++) {
            $pub->publish('demo/hello', "message-{$i}", ['qos' => 1]);
            echo "[publish] sent message-{$i}\n";
        }
        $pub->disconnect();
    })
    ->connect();

$pub->loop();
