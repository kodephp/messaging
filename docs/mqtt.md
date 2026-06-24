# MQTT 协议指南

> 实现：MQTT 3.1.1 / 5.0
> 适用：IoT 设备、移动端推送、低带宽场景、Pub/Sub、百万级设备连接

## 1. 简介

MQTT 是 OASIS 标准的轻量 Pub/Sub 协议，广泛用于 IoT 和移动推送。`kode/messaging` 提供：

- 纯 PHP 协议实现（无 `ext-mosquitto` 强依赖）
- **MQTT 3.1.1 + 5.0 双版本支持**（服务端自动识别协议级别）
- QoS 0/1/2 全支持
- Last Will and Testament（LWT）
- TLS（`mqtts://`）
- MQTT 5.0 特性：
  - Properties（会话过期间隔、接收最大值、主题别名、用户属性等）
  - Reason Code（替代 3.1.1 的 Return Code，提供更丰富的错误诊断）
  - Will Properties（遗嘱消息属性）
  - 服务端能力通告（最大 QoS、保留可用、共享订阅可用等）
  - Shared Subscription（`$share/group/topic`）
- MQTT over WebSocket（`ws://` + MQTT 协议帧，浏览器/App 穿越防火墙）

### 1.1 裸 MQTT vs MQTT over WebSocket

| 模式 | 传输层 | 默认端口 | 场景 |
|---|---|---|---|
| **裸 MQTT** | 直接 TCP | 1883 / 8883(TLS) | 设备端（充电桩、手表、传感器）— 最省资源 |
| **MQTT over WebSocket** | TCP → WebSocket → MQTT | 8083 / 8084(TLS) | 浏览器/App — 穿越防火墙 |

"裸 MQTT"就是不走 WebSocket，设备直接用 TCP 连 1883 端口，发 MQTT 协议帧。
浏览器无法发裸 TCP，所以必须套一层 WebSocket。

本包同时支持两种模式：
- `mqtt://0.0.0.0:1883` — 裸 MQTT Broker
- `ws://0.0.0.0:8083` + MQTT 子协议 — MQTT over WebSocket

## 2. 客户端

### 2.1 连接

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('mqtt://broker.example.com:1883')
    ->withClientId('device-001')
    ->withCredentials('user', 'pass')
    ->withKeepalive(60)
    ->withCleanSession(true)
    ->withProtocolVersion('5.0')     // 默认 '3.1.1'
    ->on('connect', fn() => echo "connected\n")
    ->on('error',   fn($e) => log($e->getMessage()))
    ->connect();
```

### 2.2 订阅

```php
$client->subscribe('sensors/+/temperature', function ($topic, $payload, $message) {
    echo "[$topic] $payload (qos={$message->qos()}, retain=" . ($message->isRetain() ? '1' : '0') . ")\n";
}, ['qos' => 1]);

// 多主题
$client->subscribe([
    'sensors/room-1/#' => ['qos' => 1, 'handler' => $handler1],
    'alerts/#'         => ['qos' => 2, 'handler' => $handler2],
]);
```

### 2.3 发布

```php
$client->publish(
    topic: 'sensors/room-1/temperature',
    payload: '23.5',
    options: [
        'qos'    => 1,
        'retain' => false,
        'dup'    => false,
    ]
);
```

### 2.4 Last Will

```php
$client = Messaging::client('mqtt://broker.example.com:1883')
    ->withWill(
        topic:   'devices/device-001/status',
        payload: 'offline',
        qos:     1,
        retain:  true,
    )
    ->connect();
```

设备掉线后，Broker 自动发布 `offline` 给订阅者。

### 2.5 TLS

```php
$client = Messaging::client('mqtts://broker.example.com:8883')
    ->withTls([
        'cafile'   => '/etc/ssl/certs/ca.pem',
        'local_cert' => '/etc/ssl/client.pem',
        'local_pk'   => '/etc/ssl/client.key',
        'verify_peer' => true,
    ])
    ->connect();
```

### 2.6 MQTT 5.0 特性

MQTT 5.0 引入了 Properties、Reason Code、Shared Subscription 等重要特性。
本包完整实现了 5.0 协议，服务端自动识别 3.1.1 / 5.0。

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\Adapter\Mqtt\Packet\Properties;

// 客户端：使用 5.0 连接
$client = Messaging::client('mqtt://broker.example.com:1883')
    ->withProtocolVersion('5.0')           // 选择 5.0
    ->withClientId('device-5-001')
    ->connect([
        'session_expiry_interval' => 3600,  // 会话过期 1 小时
        'receive_maximum'          => 100,  // 最多 100 个未确认
        'maximum_packet_size'      => 1048576,
        'topic_alias_maximum'      => 10,
        'user_properties'          => [
            ['app', 'kode-messaging'],
            ['env', 'production'],
        ],
    ]);

// 发布带 5.0 属性的消息
$client->publish('sensors/temp', '23.5', [
    'qos'    => 1,
    'retain' => false,
    'properties' => [
        Properties::CONTENT_TYPE           => 'text/plain',
        Properties::MESSAGE_EXPIRY_INTERVAL => 300,  // 5 分钟后过期
        Properties::USER_PROPERTY          => [['unit', 'celsius']],
    ],
]);
```

#### 5.0 服务端能力通告

服务端在 CONNACK 中返回能力通告，客户端可据此调整行为：

| 属性 | 说明 | 默认 |
|---|---|---|
| `Maximum QoS` | 服务端支持的最大 QoS | 2 |
| `Retain Available` | 是否支持保留消息 | 1 (true) |
| `Maximum Packet Size` | 最大包大小 | 0 (无限) |
| `Topic Alias Maximum` | 主题别名最大值 | 0 (禁用) |
| `Wildcard Subscription Available` | 通配符订阅 | 1 (true) |
| `Shared Subscription Available` | 共享订阅 | 1 (true) |
| `Server Keep Alive` | 服务端覆盖的 Keep Alive | 0 (用客户端值) |

#### 5.0 Reason Code

5.0 用 Reason Code 替代 3.1.1 的 Return Code，提供更丰富的错误诊断：

```php
use Kode\Messaging\Adapter\Mqtt\Packet\ReasonCode;

// 常用 Reason Code
ReasonCode::SUCCESS;                        // 0x00
ReasonCode::NOT_AUTHORIZED;                 // 0x87
ReasonCode::UNSUPPORTED_PROTOCOL_VERSION;   // 0x84
ReasonCode::BAD_USERNAME_OR_PASSWORD;       // 0x86
ReasonCode::QUOTA_EXCEEDED;                 // 0x97
ReasonCode::TOPIC_FILTER_INVALID;           // 0x8F

// 工具方法
ReasonCode::isSuccess($code);               // true if < 0x80
ReasonCode::isError($code);                 // true if >= 0x80
ReasonCode::description($code);             // 可读描述
```

## 3. 保持连接

```php
$client->loop();   // 阻塞，处理收发

// 或非阻塞
$client->loopAsync(function () {
    // 业务循环
});
```

## 4. 服务端（Broker 模式）

> 支持 MQTT 3.1.1 + 5.0 双版本，自动识别协议级别。
> 生产环境百万级连接建议使用 Mosquitto / EMQX + `kode/process` 多进程。

```php
use Kode\Messaging\Messaging;

$builder = Messaging::server('mqtt://0.0.0.0:1883')
    ->withConfig([
        'supported_versions'     => ['3.1.1', '5.0'],
        'allow_anonymous'        => false,
        'max_qos'                => 2,
        'retain_available'       => true,
        'shared_sub_available'   => true,
        'topic_alias_max'        => 10,
        'max_packet_size'        => 1048576,
        'server_keepalive'       => 0,   // 0 = 用客户端值
    ])
    ->withAuth(function ($clientId, $username, $password) {
        return $username === 'device' && $password === 'secret';
    })
    ->on('connection.open', function ($conn) {
        echo "Client connected: {$conn->getAttribute('mqtt.client_id')} "
           . "(v{$conn->getAttribute('mqtt.version')})\n";
    })
    ->on('message.received', function ($conn, $msg) {
        $ctx = $msg->context();
        // 5.0 客户端的 PUBLISH Properties 在 context['properties'] 中
        $props = $ctx['properties'] ?? [];
    })
    ->start();
```

### 4.1 服务端配置项

| 配置 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `supported_versions` | `string[]` | `['3.1.1', '5.0']` | 支持的协议版本 |
| `allow_anonymous` | `bool` | `true` | 是否允许匿名连接 |
| `max_qos` | `int` | `2` | 最大 QoS（5.0 CONNACK 通告） |
| `retain_available` | `bool` | `true` | 保留消息可用 |
| `wildcard_sub_available` | `bool` | `true` | 通配符订阅可用 |
| `sub_id_available` | `bool` | `true` | 订阅标识符可用 |
| `shared_sub_available` | `bool` | `true` | 共享订阅可用 |
| `server_keepalive` | `int` | `0` | 服务端覆盖 Keep Alive（0=不覆盖） |
| `max_packet_size` | `int` | `0` | 最大包大小（0=无限） |
| `topic_alias_max` | `int` | `0` | 主题别名最大值（0=禁用） |

## 5. 与 kode/queue 协作（QoS 1/2 落地）

```php
$client->subscribe('orders/created', function ($topic, $payload) {
    Queue::push(OrderProcessor::class, json_decode($payload, true));
});
```

依赖：

```bash
composer require kode/queue
```

## 6. 与 kode/cache 协作（会话保持）

```php
$client = Messaging::client('mqtt://broker')
    ->withSession(
        driver: 'redis',  // 'memory' | 'redis' | 'apcu'
        config: ['host' => '127.0.0.1'],
    )
    ->withCleanSession(false)
    ->connect();
```

掉线重连后未确认的 QoS 1/2 消息自动重投。

## 7. 完整示例：IoT 设备

```php
use Kode\Messaging\Messaging;

// 设备侧
$device = Messaging::client('mqtts://broker.example.com:8883')
    ->withClientId('sensor-001')
    ->withCredentials('sensor-001', $token)
    ->withWill('devices/sensor-001/status', 'offline', 1, true)
    ->on('connect', function () use ($device) {
        $device->publish('devices/sensor-001/status', 'online', 1, true);
    })
    ->withTls(['cafile' => '/etc/ssl/ca.pem', 'verify_peer' => true])
    ->connect();

// 业务侧：订阅所有传感器
$bus = Messaging::pubsub('redis');
$bus->subscribe('sensors/+/temperature', function ($payload) {
    if ($payload > 30) {
        // 触发告警
    }
});

$device->subscribe('commands/sensor-001', function ($topic, $payload) {
    // 接收控制指令
});
$device->loop();
```

完整可运行示例：[docs/examples/iot.php](./examples/iot.php)

## 8. 性能与调优

| 场景 | 调优 |
|---|---|
| 高频小包 | `withMaxInflight(1000)` |
| 低带宽 | `withKeepalive(120)` |
| 大量订阅 | 启用会话持久化（Redis） |
| 跨节点 | `pubsub('redis')` |

## 9. 常见问题

### 9.1 设备离线消息如何保留？

- retain + QoS 1/2 + 持久会话
- 设备重连时 `clean_session=false`

### 9.2 QoS 2 消息重传？

`kode/messaging` 自动处理 PUBREC/PUBREL/PUBCOMP 四次握手。

### 9.3 大量设备？

- 单进程 ~10K 设备（推荐 2-4 核）
- 集群：`kode/process` 多 Worker + Redis 路由
