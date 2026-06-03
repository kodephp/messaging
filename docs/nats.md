# NATS 协议

> 适用：微服务 Pub/Sub、request/reply、轻量消息总线
> 方案：`nats://` / `nats://user:pass@host:4222`
> 端口：默认 `4222`（TLS `4223`）

NATS 是一个简洁的**文本协议**，核心操作：

| 操作 | 说明 |
|---|---|
| `INFO` | 服务端 → 客户端，握手 |
| `CONNECT` | 客户端 → 服务端，认证与协议选项 |
| `PUB` | 发布消息 |
| `SUB` | 订阅 subject（支持通配符） |
| `UNSUB` | 取消订阅 |
| `PING` / `PONG` | 心跳 |
| `MSG` | 服务端投递消息 |
| `+OK` / `-ERR` | 命令响应 |

## Subject 通配符

- `*` 匹配单个 token：`orders.*` 匹配 `orders.created`、`orders.deleted`，不匹配 `orders.eu.created`
- `>` 匹配尾部多 token：`orders.>` 匹配 `orders.eu.created`、`orders.us.ca.created`

## 服务端

```php
use Kode\Messaging\Messaging;

Messaging::server('nats://0.0.0.0:4222')
    ->on('message.received', function ($conn, $message) {
        $subject = $message->topic();
        $payload = $message->payload();
        echo "[{$subject}] {$payload}\n";
    })
    ->start();
```

> 📌 **嵌入式 Broker**：本适配器内置一个最小 NATS Broker，适合本地开发、单元测试、边缘场景。
> 生产环境推荐使用官方 [`nats-server`](https://github.com/nats-io/nats-server)。

## 客户端

### 发布

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('nats://broker:4222');
$client->connect();
$client->publish('orders.created', json_encode(['id' => 1001]));
```

### 订阅

```php
$client = Messaging::client('nats://broker:4222');
$client->subscribe('orders.*', function ($subject, $payload) {
    echo "[{$subject}] {$payload}\n";
});
$client->connect();
$client->run(); // 进入读循环
```

### Request / Reply

```php
$client = Messaging::client('nats://broker:4222');
$client->request('time.now', '', function ($reply, $payload) {
    echo "时间: {$payload}\n";
}, timeoutMs: 1000);
$client->connect();
$client->run();
```

### 队列组（Queue Group）

```php
$client->subscribe('orders.*', $handler, queueGroup: 'workers');
```

## 配置项

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `name` | string | `kode-messaging` | 客户端标识 |
| `pedantic` | bool | `false` | 严格模式 |
| `verbose` | bool | `false` | 详细日志 |
| `ping_interval` | int | `30` | 心跳间隔（秒），0 关闭 |
| `max_payload` | int | `1048576` | 消息最大字节 |

## 协议事件

| 事件 | 参数 |
|---|---|
| `connection.open` | `$connection` |
| `message.received` | `$connection`, `$message`（`$message->topic()` 是 subject） |
| `connection.close` | `$connection` |
| `error.protocol` | `$peer`, `$error` |

## 安全建议

- 生产场景使用 TLS：`nats://tls://...` 或前置代理
- 不在 payload 中传递敏感数据
- 鉴权：当前版本使用明文 CONNECT headers；生产建议使用 TLS + token 扩展
