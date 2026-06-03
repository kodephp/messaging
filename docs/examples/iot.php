<?php
/**
 * MQTT IoT 设备示例
 *
 * 依赖：composer require kode/messaging
 * 需要：本地或远程 MQTT Broker（Mosquitto / EMQX）
 *
 * 运行：MQTT_HOST=broker.example.com php docs/examples/iot.php
 *
 * 演示：
 *  - 模拟 3 个传感器发布温度/湿度数据
 *  - 同时订阅所有传感器数据，超阈值告警
 *  - 接收控制指令（如重启、配置更新）
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Messaging\Messaging;

$broker = getenv('MQTT_HOST') ?: '127.0.0.1';
$port   = (int)(getenv('MQTT_PORT') ?: 1883);

// === 1. 模拟传感器：发布数据 ===
$pub = Messaging::client("mqtt://{$broker}:{$port}")
    ->withClientId('iot-gateway-pub')
    ->withCredentials('gateway', 'secret')
    ->withWill('gateway/status', 'offline', 1, true)
    ->on('connect', function () use ($pub) {
        $pub->publish('gateway/status', 'online', 1, true);
        echo "[gateway] online\n";
    })
    ->connect();

$counter = 0;
$pub->loopAsync(function () use ($pub, &$counter) {
    $counter++;
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $temp = 20 + mt_rand(0, 200) / 10;   // 20-40
        $humi = 40 + mt_rand(0, 400) / 10;   // 40-80
        $pub->publish("sensors/{$room}/temperature", (string)$temp, ['qos' => 0]);
        $pub->publish("sensors/{$room}/humidity",    (string)$humi, ['qos' => 0]);
    }
    if ($counter > 60) {
        echo "[gateway] done\n";
        $pub->disconnect();
    }
    usleep(500_000);
});

// === 2. 业务侧：订阅并告警 ===
$sub = Messaging::client("mqtt://{$broker}:{$port}")
    ->withClientId('iot-monitor-sub')
    ->withCredentials('monitor', 'secret')
    ->on('connect', function () use ($sub) {
        $sub->subscribe('sensors/+/+', function ($topic, $payload, $message) {
            $value = (float)$payload;
            if (str_contains($topic, 'temperature') && $value > 30) {
                echo "[ALERT] {$topic} = {$value}°C 超阈值！\n";
            }
        }, ['qos' => 1]);

        $sub->subscribe('commands/#', function ($topic, $payload) {
            echo "[CMD] {$topic} = {$payload}\n";
            // 处理指令...
        });
    })
    ->connect();

echo "[monitor] running... (Ctrl+C to stop)\n";
$sub->loop();
