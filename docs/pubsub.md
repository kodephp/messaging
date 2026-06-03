# 发布订阅总线

> 跨协议、跨进程、跨节点统一事件总线

## 1. 三种驱动

| 驱动 | 范围 | 性能 | 适用 |
|---|---|---|---|
| `memory` | 进程内 | 极快 | 单进程 |
| `channel` | 多进程（kode/process） | 极快 | 多 Worker 单机 |
| `redis` | 跨节点 | 高 | 集群 |

## 2. 基础用法

### 2.1 进程内

```php
use Kode\Messaging\Messaging;

$bus = Messaging::pubsub(); // 默认 memory

$bus->subscribe('user.created', function ($payload) {
    // 业务处理
});

$bus->publish('user.created', ['id' => 1001]);
```

### 2.2 跨进程（kode/process）

```php
$bus = Messaging::pubsub('channel');

// A Worker 中发布
$bus->publish('order:created', ['id' => 1001]);

// B Worker 中订阅
$bus->subscribe('order:created', fn($p) => handle($p));
```

### 2.3 跨节点（Redis）

```php
$bus = Messaging::pubsub('redis', [
    'host' => '127.0.0.1',
    'port' => 6379,
    'db'   => 0,
    'prefix' => 'messaging:',
]);

$bus->publish('global:event', $data);
```

## 3. 与协议集成

### 3.1 WebSocket + Pub/Sub（聊天室）

```php
$bus = Messaging::pubsub('memory');

Messaging::server('ws://0.0.0.0:8080')
    ->on('message.received', function ($conn, $msg) use ($bus) {
        $bus->publish('chat:room', [
            'id'      => $conn->id(),
            'payload' => $msg->payload(),
        ]);
    })
    ->start();

// 同一进程内其它地方订阅
$bus->subscribe('chat:room', function ($data) {
    saveToDatabase($data);
});
```

### 3.2 多协议互通

```php
// 业务侧发到总线
$bus->publish('notify:42', ['title' => '新消息']);

// 同一进程内：
//   - WebSocket 连接收到推送
//   - SSE 连接收到推送
//   - MQTT 设备收到消息
//   - 内部业务逻辑触发
$bus->subscribe('notify:42', function ($payload) {
    $wsClients->send($payload);
    $sseClients->send($payload);
    $mqttClient->publish('devices/42/notify', json_encode($payload));
});
```

## 4. 主题模式

支持 MQTT 风格通配符：

```php
// 精确匹配
$bus->subscribe('order.created', $handler);

// 单级通配符
$bus->subscribe('order.*', $handler);        // 匹配 order.created, order.paid

// 多级通配符
$bus->subscribe('order.#', $handler);        // 匹配 order.created, order.created.sub, ...

// 共享订阅（集群负载均衡）
$bus->subscribe('order.#', $handler, ['shared' => 'group-1']);
```

## 5. 消息确认

```php
$bus->subscribe('critical.event', function ($payload, $ack) {
    try {
        process($payload);
        $ack->ack();      // 显式确认
    } catch (\Throwable $e) {
        $ack->nack($e);    // 重试 / 进死信
    }
});
```

## 6. 序列化

默认 JSON，可自定义：

```php
$bus = Messaging::pubsub('memory')
    ->withSerializer(new MsgPackSerializer());

$bus->withSerializer(new Serializer\PhpSerializer());
```

内置：

- `JsonSerializer`（默认）
- `PhpSerializer`（保留类型）
- `MsgPackSerializer`（需 `ext-msgpack`）
- 自定义：实现 `SerializerInterface`

## 7. 高级特性

### 7.1 延迟消息

```php
$bus->publish('order.timeout', $payload, ['delay' => 60]); // 60s 后投递
```

依赖：`kode/queue`。

### 7.2 死信队列

```php
$bus->withDeadLetter('bus:dead-letter', maxRetries: 3);
```

### 7.3 顺序保证

`channel` 驱动单 topic 内严格有序；`redis` 跨节点同 partition key 有序。

## 8. 监控

```php
$bus->withMetrics(new PrometheusExporter([
    'namespace' => 'messaging',
    'subsystem' => 'pubsub',
]));
```

暴露指标：

- `messaging_pubsub_published_total{topic}`
- `messaging_pubsub_received_total{topic,result}`
- `messaging_pubsub_lag_seconds{topic}`

## 9. 完整示例

参见 `docs/examples/rpc.php` 中基于 Pub/Sub 的 RPC 模式。
