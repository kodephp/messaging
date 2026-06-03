<?php

/**
 * CoAP 服务端示例（IoT 传感器网关）
 *
 * 提供两个资源：
 *  - GET  /sensors/temp     → 返回当前温度
 *  - PUT  /sensors/temp     → 写入温度数据
 *  - GET  /health           → 健康检查
 *
 * 启动：
 *  php examples/coap_server.php
 *
 * 测试（用 libcoap 工具或 CoAP 客户端）：
 *  coap-client -m get coap://127.0.0.1:5683/sensors/temp
 *  coap-client -m put -e "23.5" coap://127.0.0.1:5683/sensors/temp
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Adapter\Coap\CoapCode;
use Kode\Messaging\Adapter\Coap\CoapOption;
use Kode\Messaging\Adapter\Coap\CoapType;
use Kode\Messaging\Messaging;

$dataFile = '/tmp/coap-sensor-temp';

$builder = Messaging::server('coap://0.0.0.0:5683')
    ->on('connection.open', function ($conn) {
        echo "[open] {$conn->remoteAddress()}\n";
    })
    ->on('message.received', function ($conn, $message) use ($dataFile) {
        $ctx = $message->headers();
        $coap = $ctx['coap'] ?? [];
        $method = $coap['method'] ?? 'GET';
        $path = $coap['path'] ?? '/';
        $mid = (int)($coap['mid'] ?? 0);
        $token = (string)($coap['token'] ?? '');

        echo "[recv] {$method} {$path} from {$conn->remoteAddress()} payload=" . substr((string)$message->payload(), 0, 80) . "\n";

        $respond = function (float $code, string $body, int $cf = CoapOption::FMT_JSON) use ($conn, $mid, $token) {
            $conn->sendRequest($code, '', $body, [
                'mid'   => $mid,
                'token' => $token,
                'type'  => CoapType::ACK,
                'content_format' => $cf,
            ]);
        };

        if ($path === '/sensors/temp' && $method === 'GET') {
            $value = file_exists($dataFile) ? trim((string)file_get_contents($dataFile)) : 'unknown';
            $respond(CoapCode::CONTENT, json_encode([
                'value' => $value,
                'unit'  => 'C',
                'ts'    => time(),
            ]));
        } elseif ($path === '/sensors/temp' && $method === 'PUT') {
            @file_put_contents($dataFile, (string)$message->payload());
            $respond(CoapCode::CHANGED, '');
        } elseif ($path === '/health' && $method === 'GET') {
            $respond(CoapCode::CONTENT, json_encode(['ok' => true]));
        } else {
            $respond(CoapCode::NOT_FOUND, 'Not Found', CoapOption::FMT_TEXT);
        }
    })
    ->on('coap.timeout', function ($info) {
        echo "[timeout] mid={$info['mid']} peer={$info['peer']}\n";
    })
    ->on('coap.retransmit', function ($info) {
        echo "[retransmit] mid={$info['mid']} attempts={$info['attempts']}\n";
    })
    ->on('connection.close', function ($conn) {
        echo "[close] {$conn->remoteAddress()}\n";
    });

echo "CoAP server started at coap://0.0.0.0:5683\n";
$builder->start();
