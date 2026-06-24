# kode/messaging

**统一消息层 | WebSocket / SSE / MQTT / UDP / Long-Polling / CoAP / NATS / STOMP / gRPC / WebTransport / RTMP | PHP 8.2+ | PSR 合规 | 可插拔适配器**

[![PHP Version](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache%202.0-green?style=flat-square)](LICENSE)
[![kode](https://img.shields.io/badge/kode-family-blue?style=flat-square)](https://packagist.org/packages/kode/)
[![Version](https://img.shields.io/badge/version-2.4.0-blue?style=flat-square)](CHANGELOG.md)

## 简介

`kode/messaging` 是 `kode/*` 家族中的**统一消息层** Composer 包，封装 **WebSocket**、**SSE**、**MQTT**、**UDP**、**Long-Polling**、**CoAP**、**NATS**、**STOMP**、**gRPC**、**WebTransport**、**RTMP** 等 11 种长连接 / 实时消息协议，提供**一致的 API**、**协议无关的消息抽象**和**可插拔的扩展点**。

一个 `Messaging::server()` 启动所有协议，**业务代码面向接口编程，不感知具体协议**。

## 特性

| 特性 | 说明 |
|---|---|
| 🌐 **11 协议统一** | WebSocket / SSE / MQTT / UDP / Long-Polling / CoAP / NATS / STOMP / gRPC / WebTransport / RTMP |
| 🔌 **可插拔适配器** | 新增协议不改动核心代码（3 步：Adapter / 注册 / 文档） |
| 📡 **协议无关** | 业务层只依赖 `MessageInterface` |
| 🧩 **中间件管道** | 鉴权 / 限流 / 编解码 / 校验 / 追踪 |
| 🏷️ **路由** | 基于 event / topic 路由消息 |
| 📢 **统一 Pub/Sub** | 进程内 / 跨进程 / 跨节点（Redis） |
| 🛡️ **PSR 合规** | PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18 |
| ⚡ **协程友好** | 与 `kode/fibers` 协作，无 Fiber 自动降级 |
| 🔒 **安全** | TLS、JWT、Origin 校验、签名鉴权、限流 |
| 📊 **可观测** | 事件、指标、结构化日志 |
| 🚀 **高性能** | Swoole / Swow / stream 多传输层 |
| 🔁 **重连 / 心跳** | 客户端内置指数退避重连、ping/pong |

## 安装

```bash
composer require kode/messaging
```

按需安装可选依赖：

```bash
composer require kode/fibers        # 协程
composer require kode/event         # 事件派发
composer require kode/process       # 多 Worker / 集群
composer require kode/queue         # MQTT QoS 落地、延迟消息
composer require kode/jwt           # JWT 鉴权
composer require nyholm/psr7       # SSE 的 PSR-7 基础
```

## 快速开始

### WebSocket

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', fn($c) => $c->send('welcome'))
    ->on('message.received', fn($c, $m) => $c->send("echo: {$m->payload()}"))
    ->start();
```

### SSE

```php
Messaging::server('sse://0.0.0.0:8081')
    ->interval(1000)
    ->on('interval', fn($c) => $c->send(['event' => 'tick', 'data' => time()]))
    ->start();
```

### MQTT

客户端（连接外部 Broker）：

```php
Messaging::client('mqtt://broker.example.com:1883')
    ->withClientId('device-001')
    ->subscribe('sensors/+/temperature', function ($topic, $payload) {
        echo "[$topic] $payload\n";
    })
    ->connect()
    ->loop();
```

Broker（内置 MQTT 3.1.1 服务端，支持 QoS 0/1/2、主题通配符、保留消息、遗嘱消息）：

```php
Messaging::server('mqtt://0.0.0.0:1883')
    ->on('connection.open', fn($c) => log_connect($c))
    ->on('message.received', function ($c, $m) {
        $topic = $m->topic();
        $payload = $m->payload();
        echo "[mqtt] topic={$topic} payload={$payload}\n";
    })
    ->start();
```

### NATS（pub/sub、request/reply）

```php
$client = Messaging::client('nats://broker:4222');
$client->subscribe('orders.*', function ($subject, $payload) {
    echo "[$subject] $payload\n";
});
$client->connect();
$client->publish('orders.created', json_encode(['id' => 1]));
```

### STOMP（消息队列客户端）

```php
$client = Messaging::client('stomp://broker:61613');
$client->subscribe('/queue/orders', function ($data) {
    echo $data['body'] . "\n";
});
$client->connect();
$client->send('/queue/orders', 'hello');
```

### gRPC Streaming

```php
$client = Messaging::client('grpc://api.example.com:50051');
$response = $client->call('/helloworld.Greeter/SayHello', $reqPayload);
```

### WebTransport（HTTP/3 双工）

```php
$client = Messaging::client('webtransport://example.com:4433');
$conn = $client->connect();
$conn->sendBidirectional('hello');
$conn->sendDatagram('ping', reliable: false);
```

### RTMP（直播源接入）

```php
Messaging::server('rtmp://0.0.0.0:1935')
    ->on('message.received', fn($c, $m) => log_rtmp($m))
    ->start();
```

### MQTT + WebSocket 组合（IoT 百万设备方案）

设备端（充电桩、手表等）用裸 MQTT（tcp://1883），用户端（App、网页管理后台）用 MQTT over WebSocket（ws://8083），两种客户端连同一个 Broker，消息互通。

```php
// 设备端：裸 MQTT（高效、省资源）
Messaging::server('mqtt://0.0.0.0:1883')
    ->on('message.received', fn($c, $m) => handle_device($m))
    ->start();

// 用户端：MQTT over WebSocket（穿越防火墙）
Messaging::server('mqtt+ws://0.0.0.0:8083')
    ->on('message.received', fn($c, $m) => handle_app($m))
    ->start();

// 集群模式：百万设备连接（多节点通过 Redis 总线同步）
Messaging::server('mqtt://0.0.0.0:1883')
    ->withCluster(true, 'node-1')  // 启用 Redis 跨节点消息路由
    ->start();
```

浏览器端使用 MQTT.js 连接：
```javascript
const client = mqtt.connect('ws://127.0.0.1:8083/mqtt', {
  protocol: 'mqtt',  // WebSocket subprotocol
  clientId: 'web-admin-001'
});
client.subscribe('sensors/+/temperature');
client.on('message', (topic, payload) => console.log(topic, payload.toString()));
```

## 协议矩阵

| 协议 | 方案 | 服务端 | 客户端 | 适用 |
|---|---|---|---|---|
| WebSocket | `ws://` / `wss://` | ✅ | ✅ | 浏览器长连接、聊天、游戏 |
| SSE | `sse://` | ✅ | ✅ | 服务端推送、通知、大屏 |
| MQTT 3.1.1 / 5.0 | `mqtt://` / `mqtts://` | ✅ Broker | ✅ | IoT、移动推送、Pub/Sub |
| MQTT over WebSocket | `mqtt+ws://` / `mqtt+wss://` | ✅ | ✅ | App/网页管理后台、穿越防火墙 |
| UDP / Datagram | `udp://` | ✅ | ✅ | 实时音视频、游戏、广播 |
| Long-Polling | `poll://` / `http://` | ✅ | ✅ | WebSocket 回退、低频推送 |
| CoAP (RFC 7252) | `coap://` / `coaps://` | ✅ | ✅ | IoT 传感器、NB-IoT、LoRa |
| NATS | `nats://` | ✅ 嵌入式 Broker | ✅ | 微服务 Pub/Sub、request/reply |
| STOMP 1.2 | `stomp://` | ✅ 嵌入式 Broker | ✅ | 消息队列（兼容 RabbitMQ / ActiveMQ） |
| gRPC Streaming | `grpc://` | ✅ | ✅ | 微服务 RPC、4 种流式调用 |
| WebTransport | `wt://` / `webtransport://` | HTTP/3-fallback | ✅ | HTTP/3 双工（依赖 aioquic / msquic） |
| RTMP | `rtmp://` / `rtmps://` | ✅ | ✅ | 直播源接入（OBS / FMLE） |

## 架构

```
┌──────────────────────────────────────────────────────────────┐
│  Layer 5 — 应用层                                             │
│           Messaging::server()->on('message.received')        │
├──────────────────────────────────────────────────────────────┤
│  Layer 4 — 中间件管道（Auth → RateLimit → Codec → ...）       │
├──────────────────────────────────────────────────────────────┤
│  Layer 3 — 协议适配器（WebSocket / SSE / MQTT / ...）         │
├──────────────────────────────────────────────────────────────┤
│  Layer 2 — 消息抽象（MessageInterface / Connection）          │
├──────────────────────────────────────────────────────────────┤
│  Layer 1 — 传输层（TransportInterface）                      │
│           stream / sockets / swoole / swow / workerman       │
│           RuntimeDetector 自动检测最佳驱动                    │
└──────────────────────────────────────────────────────────────┘
```

## 传输层（多运行时兼容）

`kode/messaging` 通过 `TransportInterface` 抽象底层 socket 操作，支持 5 种传输驱动：

| 驱动 | 依赖 | 性能 | 说明 |
|---|---|---|---|
| `stream` | 零依赖（内置） | 基准 | 纯 PHP `stream_socket_*`，始终可用 |
| `sockets` | `ext-sockets` | +20-50% | 底层 socket 扩展 |
| `swoole` | `ext-swoole` | 100x | Swoole 协程，百万级并发 |
| `swow` | `ext-swow` | 100x | Swow 协程，跨平台 |
| `workerman` | `workerman/workerman` | 50x | Workerman 事件循环，多进程 |

**自动检测**：配置 `transport: auto` 时，`TransportFactory` 按优先级自动选择：
swoole > swow > workerman > stream。

```php
// 手动指定传输层
Messaging::configure(['transport' => 'swoole']);

// 或运行时检测
use Kode\Messaging\Kode;
echo Kode::runtime(); // 'swoole' | 'swow' | 'workerman' | 'plain'
```

## 与 kode/* 家族协作

| 场景 | 依赖包 | 协作方式 |
|---|---|---|
| 日志 | `kode/log` 或 PSR-3 | `LoggerInterface` 注入 |
| 协程 | `kode/fibers` | 长连接内启动 Fiber |
| 事件 | `kode/event` | `connection.open` 等事件派发 |
| 上下文 | `kode/context` | 连接 ID、追踪 |
| 队列 | `kode/queue` | MQTT QoS 1/2 落地 |
| 进程 | `kode/process` | 多 Worker、集群 |
| 缓存 | `kode/cache` | 会话、广播订阅 |
| HTTP | `kode/http` / `nyholm/psr7` | SSE 复用 HTTP |
| HTTP 客户端 | `kode/http-client` | 长轮询回退 |
| 鉴权 | `kode/jwt` | JWT 鉴权中间件 |

## PHP 8.2 / 8.3 / 8.4 / 8.5 兼容

- **最低**：PHP 8.2
- **推荐**：PHP 8.3 / 8.4
- **已验证**：PHP 8.5

支持的现代特性（通过 `PhpCompat` / `RuntimeDetector` 运行时检测，自动降级）：

| 特性 | 用法 | 版本 | 降级方案 |
|---|---|---|---|
| `readonly class` | 不可变消息体 | ≥ 8.2 | — |
| `enum` | 协议状态机 | ≥ 8.1 | — |
| `Fibers` | 协程 | ≥ 8.1 | — |
| `json_validate()` | JSON 快速校验 | ≥ 8.3 | `json_decode` + `json_last_error` |
| `Random\Randomizer` | 安全随机 | ≥ 8.3 | `random_bytes()` |
| typed class constants | 协议常量 | ≥ 8.3 | 普通 `const`（当前使用） |
| `#[\Override]` | 覆盖标记 | ≥ 8.3 | 静默忽略（8.2 安全） |
| property hooks | 连接属性 | ≥ 8.4 | 传统 getter/setter |
| pipe operator `\|>` | 链式构造 | ≥ 8.5 | `Kode::pipe()` foreach 模拟 |

```php
use Kode\Messaging\Kode;

// JSON 校验（8.3+ 用 json_validate，8.2 降级）
Kode::jsonValidate('{"ok":true}'); // bool

// 安全随机（8.3+ 用 Randomizer，8.2 降级）
Kode::randomBytes(16); // string

// 运行时环境检测
Kode::runtime();       // 'swoole' | 'swow' | 'workerman' | 'plain'
Kode::inCoroutine();   // bool
```

## 文档

- [docs/index.md](./docs/index.md) — 文档总览
- [docs/quick-start.md](./docs/quick-start.md) — 快速开始
- [docs/architecture.md](./docs/architecture.md) — 架构设计
- [docs/websocket.md](./docs/websocket.md) — WebSocket 协议指南
- [docs/sse.md](./docs/sse.md) — SSE 协议指南
- [docs/mqtt.md](./docs/mqtt.md) — MQTT 协议指南
- [docs/udp.md](./docs/udp.md) — UDP 协议指南
- [docs/long-polling.md](./docs/long-polling.md) — Long-Polling 协议指南
- [docs/coap.md](./docs/coap.md) — CoAP 协议指南
- [docs/nats.md](./docs/nats.md) — NATS 协议指南
- [docs/stomp.md](./docs/stomp.md) — STOMP 协议指南
- [docs/grpc.md](./docs/grpc.md) — gRPC Streaming 协议指南
- [docs/webtransport.md](./docs/webtransport.md) — WebTransport 协议指南
- [docs/rtmp.md](./docs/rtmp.md) — RTMP 协议指南
- [docs/roadmap.md](./docs/roadmap.md) — 协议扩展路线图
- [docs/pubsub.md](./docs/pubsub.md) — 发布订阅总线
- [docs/middleware.md](./docs/middleware.md) — 中间件
- [docs/configuration.md](./docs/configuration.md) — 配置
- [docs/deployment.md](./docs/deployment.md) — 部署
- [docs/release.md](./docs/release.md) — 发布流程
- [docs/migration.md](./docs/migration.md) — 从其它框架迁移
- [docs/examples/](./docs/examples/) — 完整示例

## 示例

- `examples/websocket_server.php` — WebSocket 服务端
- `examples/websocket_client.php` — WebSocket 客户端
- `examples/sse_server.php` — SSE 服务端
- `examples/mqtt_publish.php` — MQTT 发布
- `examples/mqtt_subscribe.php` — MQTT 订阅
- `examples/udp_client.php` — UDP 客户端
- `examples/coap_server.php` / `coap_client.php` — CoAP 服务端 / 客户端
- `examples/nats_server.php` / `nats_client.php` — NATS 服务端 / 客户端
- `examples/stomp_server.php` — STOMP 服务端
- `examples/grpc_server.php` — gRPC 服务端
- `examples/longpolling_server.php` / `longpolling_client.php` — Long-Polling
- `examples/webtransport_server.php` — WebTransport
- `examples/rtmp_server.php` — RTMP 直播源接入
- `docs/examples/chat.php` — 聊天室
- `docs/examples/push.php` — 实时通知
- `docs/examples/iot.php` — IoT 设备
- `docs/examples/rpc.php` — RPC over WebSocket

## 许可证

Apache-2.0
