<?php

/**
 * CoAP 客户端示例
 *
 * 向 CoAP 服务端发起 GET /sensors/temp 请求并打印响应。
 *
 * 启动：
 *  php examples/coap_client.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Adapter\Coap\CoapCode;
use Kode\Messaging\Adapter\Coap\CoapConnection;
use Kode\Messaging\Adapter\Coap\CoapOption;
use Kode\Messaging\Adapter\Coap\CoapType;
use Kode\Messaging\Messaging;

$conn = Messaging::client('coap://127.0.0.1:5683')->connect();

if (! ($conn instanceof CoapConnection)) {
    fwrite(STDERR, "Unexpected connection type\n");
    exit(1);
}

// 1) GET /sensors/temp
echo "→ GET /sensors/temp\n";
$conn->sendRequest(CoapCode::GET, '/sensors/temp', '', [
    'accept' => CoapOption::FMT_JSON,
    'type' => CoapType::CON,  // 可靠传输
    'token' => "\x01",
]);

// 等待响应（实际场景中应注册消息回调）
sleep(1);

// 2) PUT /sensors/temp
echo "→ PUT /sensors/temp\n";
$conn->sendRequest(CoapCode::PUT, '/sensors/temp', '23.7', [
    'content_format' => CoapOption::FMT_TEXT,
    'type' => CoapType::CON,
    'token' => "\x02",
]);
sleep(1);

// 3) NON 心跳
echo "→ POST /heartbeat (NON)\n";
$conn->sendRequest(CoapCode::POST, '/heartbeat', 'ping', [
    'type' => CoapType::NON,
]);

echo "Done.\n";
