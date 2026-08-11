<?php

/**
 * kode/messaging 协议懒注册
 *
 * 在 composer "files" 自动加载中引入。
 *
 * 关键点：使用「完全限定类名 ::class」注册到 Registry。
 * `::class` 在编译期即解析为字符串，**不会触发类自动加载**，
 * 因此 `require vendor/autoload.php` 时不会加载任何适配器类。
 *
 * 适配器类仅在 `Registry::make($scheme)` 真正实例化时才按需加载，
 * 相比原先逐个调用 `Xxx::autoRegister()`（每次调用都会触发对应类加载），
 * 单协议消费者可显著减少启动时的类加载开销。
 *
 * 注册顺序与原 autoRegister() 调用一致：Client 先、Server 后（Server 覆盖），
 * 最终每个 scheme 指向 Server 适配器，行为与原实现完全等价。
 */

declare(strict_types=1);

use Kode\Messaging\Adapter\Registry;

// CoAP
Registry::register('coap', Kode\Messaging\Adapter\Coap\Client::class);
Registry::register('coap', Kode\Messaging\Adapter\Coap\Server::class);

// gRPC
Registry::register('grpc', Kode\Messaging\Adapter\Grpc\Client::class);
Registry::register('grpc', Kode\Messaging\Adapter\Grpc\Server::class);

// Long-Polling
Registry::register('long-polling', Kode\Messaging\Adapter\LongPolling\Client::class);
Registry::register('long-polling', Kode\Messaging\Adapter\LongPolling\Server::class);

// MQTT
Registry::register('mqtt', Kode\Messaging\Adapter\Mqtt\Client::class);
Registry::register('mqtt', Kode\Messaging\Adapter\Mqtt\Server::class);
Registry::register('mqtt+ws', Kode\Messaging\Adapter\Mqtt\MqttOverWsClient::class);
Registry::register('mqtt+ws', Kode\Messaging\Adapter\Mqtt\MqttOverWsServer::class);
Registry::register('mqtt+wss', Kode\Messaging\Adapter\Mqtt\MqttOverWsServer::class);
Registry::register('mqtt+ws-client', Kode\Messaging\Adapter\Mqtt\MqttOverWsClient::class);

// NATS
Registry::register('nats', Kode\Messaging\Adapter\Nats\Client::class);
Registry::register('nats', Kode\Messaging\Adapter\Nats\Server::class);

// RTMP
Registry::register('rtmp', Kode\Messaging\Adapter\Rtmp\Server::class);

// SSE
Registry::register('sse', Kode\Messaging\Adapter\Sse\Client::class);
Registry::register('sse', Kode\Messaging\Adapter\Sse\Server::class);

// STOMP
Registry::register('stomp', Kode\Messaging\Adapter\Stomp\Client::class);
Registry::register('stomp', Kode\Messaging\Adapter\Stomp\Server::class);

// UDP
Registry::register('udp', Kode\Messaging\Adapter\Udp\Client::class);
Registry::register('udp', Kode\Messaging\Adapter\Udp\Server::class);

// WebSocket
Registry::register('ws', Kode\Messaging\Adapter\WebSocket\Client::class);
Registry::register('ws', Kode\Messaging\Adapter\WebSocket\Server::class);

// WebTransport
Registry::register('webtransport', Kode\Messaging\Adapter\WebTransport\Client::class);
Registry::register('webtransport', Kode\Messaging\Adapter\WebTransport\Server::class);
