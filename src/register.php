<?php

/**
 * kode/messaging 协议自动注册
 *
 * 在 composer "files" 自动加载中引入，
 * 一次性把 6 个内置协议适配器全部注册到 Registry。
 *
 * 这样业务代码无需手动 `new Xxx\Server()->autoRegister()`，
 * 引用 `vendor/autoload.php` 后即开即用。
 */

declare(strict_types=1);

use Kode\Messaging\Adapter\Coap\Client as CoapClient;
use Kode\Messaging\Adapter\Coap\Server as CoapServer;
use Kode\Messaging\Adapter\LongPolling\Client as LpClient;
use Kode\Messaging\Adapter\LongPolling\Server as LpServer;
use Kode\Messaging\Adapter\Mqtt\Client as MqttClient;
use Kode\Messaging\Adapter\Sse\Client as SseClient;
use Kode\Messaging\Adapter\Sse\Server as SseServer;
use Kode\Messaging\Adapter\Udp\Client as UdpClient;
use Kode\Messaging\Adapter\Udp\Server as UdpServer;
use Kode\Messaging\Adapter\WebSocket\Client as WsClient;
use Kode\Messaging\Adapter\WebSocket\Server as WsServer;

CoapClient::autoRegister();
CoapServer::autoRegister();
LpClient::autoRegister();
LpServer::autoRegister();
MqttClient::autoRegister();
SseClient::autoRegister();
SseServer::autoRegister();
UdpClient::autoRegister();
UdpServer::autoRegister();
WsClient::autoRegister();
WsServer::autoRegister();
