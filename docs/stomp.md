# STOMP 协议

> 适用：消息队列（兼容 RabbitMQ / ActiveMQ Artemis）
> 方案：`stomp://` / `stomps://`
> 端口：默认 `61613`（TLS `61612`）

STOMP（Simple/Streaming Text Oriented Messaging Protocol）是一个**基于文本帧**的协议，每个帧形如：

```
COMMAND\n
header:value\n
header:value\n
\n
body\x00
```

## 核心命令

| 方向 | 命令 | 说明 |
|---|---|---|
| C → S | `CONNECT` / `STOMP` | 握手 |
| C → S | `SEND` | 发送消息到 destination |
| C → S | `SUBSCRIBE` | 订阅 destination |
| C → S | `UNSUBSCRIBE` | 取消订阅 |
| C → S | `ACK` / `NACK` | 确认 / 拒收（事务型） |
| C → S | `BEGIN` / `COMMIT` / `ABORT` | 事务 |
| C → S | `DISCONNECT` | 关闭 |
| S → C | `CONNECTED` | 握手响应 |
| S → C | `MESSAGE` | 推送消息 |
| S → C | `RECEIPT` | 回执 |
| S → C | `ERROR` | 错误 |

## 服务端

```php
use Kode\Messaging\Messaging;

Messaging::server('stomp://0.0.0.0:61613')
    ->on('message.received', function ($conn, $message) {
        $body = $message->payload();
        $destination = $message->topic();
        echo "[{$destination}] {$body}\n";
    })
    ->start();
```

> 📌 **嵌入式 Broker**：本适配器内置一个最小 STOMP 1.2 Broker，适合本地开发、单元测试、嵌入式场景。
> 生产环境推荐使用 RabbitMQ / ActiveMQ Artemis。

## 客户端

### 发送

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('stomp://broker:61613');
$client->connect();
$client->send('/queue/orders', json_encode(['id' => 1]));
```

### 订阅

```php
$client = Messaging::client('stomp://broker:61613');
$client->subscribe('/queue/orders', function ($data) {
    echo $data['body'] . "\n";
});
$client->connect();
$client->run();
```

## 鉴权

```php
$client = Messaging::client('stomp://user:pass@broker:61613');
$client->connect();
```

## 配置项

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `login` | string | `null` | 用户名 |
| `passcode` | string | `null` | 密码 |
| `client_id` | string | `null` | 客户端标识 |
| `heartbeat_ms` | int | `10000` | 心跳间隔（毫秒） |

## 协议事件

| 事件 | 参数 |
|---|---|
| `connection.open` | `$connection` |
| `message.received` | `$connection`, `$message` |
| `connection.close` | `$connection`, `$reason` |
| `error.protocol` | `$peer`, `$error` |

## 与 RabbitMQ 集成

```php
// RabbitMQ 默认开启 STOMP 插件
$client = Messaging::client('stomp://guest:guest@rabbit:61613');
$client->subscribe('/queue/orders', $handler);
$client->connect();
```

## 安全建议

- 生产使用 `stomps://` + TLS
- 启用 RabbitMQ STOMP 插件的 `ssl` 选项
- 业务层校验 destination，避免任意路径写入
