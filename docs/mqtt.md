# MQTT 协议指南

> 实现：MQTT 3.1.1 / 5.0
> 适用：IoT 设备、移动端推送、低带宽场景、Pub/Sub

## 1. 简介

MQTT 是 OASIS 标准的轻量 Pub/Sub 协议，广泛用于 IoT 和移动推送。`kode/messaging` 提供：

- 纯 PHP 协议实现（无 `ext-mosquitto` 强依赖）
- QoS 0/1/2 全支持
- Last Will and Testament（LWT）
- TLS（`mqtts://`）
- MQTT 5.0 特性（user properties, reason codes, etc.）

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

```php
$client = Messaging::client('mqtt://broker.example.com:1883')
    ->withProtocolVersion('5.0')
    ->withUserProperties(['app' => 'kode-messaging'])
    ->withReceiveMaximum(100)
    ->withSessionExpiryInterval(3600)
    ->connect();
```

## 3. 保持连接

```php
$client->loop();   // 阻塞，处理收发

// 或非阻塞
$client->loopAsync(function () {
    // 业务循环
});
```

## 4. 服务端（Broker 模式 / 实验性）

> ⚠️ Broker 实现为实验性，生产建议使用 Mosquitto / EMQX。

```php
use Kode\Messaging\Messaging;

Messaging::server('mqtt://0.0.0.0:1883')
    ->withPersistence('redis', ['host' => '127.0.0.1'])  // 保留消息
    ->withAuth(function ($clientId, $username, $password) {
        return $username === 'device' && $password === 'secret';
    })
    ->on('subscribe', function ($clientId, $topicFilter, $qos) {
        log("$clientId subscribed $topicFilter (qos=$qos)");
    })
    ->on('publish', function ($clientId, $topic, $payload, $qos, $retain) {
        // 可拦截/转换消息
        return compact('topic', 'payload', 'qos', 'retain');
    })
    ->start();
```

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
