# 迁移指南

从其它框架迁移到 `kode/messaging`。

## 1. 从 Workerman 迁移

Workerman 启动方式：

```php
// workerman
use Workerman\Worker;

$worker = new Worker('websocket://0.0.0.0:8080');
$worker->onMessage = function ($connection, $data) {
    $connection->send('echo: ' . $data);
};
Worker::runAll();
```

迁移到 `kode/messaging`：

```php
// kode/messaging
use Kode\Messaging\Messaging;

Messaging::server('ws://0.0.0.0:8080')
    ->on('message.received', function ($conn, $message) {
        $conn->send('echo: ' . $message->payload());
    })
    ->start();
```

### 1.1 进程模型

| Workerman | kode/messaging |
|---|---|
| `count(4)` | `Kode\Process\Kode::worker()->count(4)` |
| 守护进程 | `daemonize(true)` (process 包) |
| `reload` 信号 | `kode/process` 同样支持 |
| `status` 命令 | `kode/process` 同样支持 |

### 1.2 协议差异

- Workerman `onConnect` → `connection.open`
- Workerman `onMessage($conn, $data)` → `message.received($conn, $message)`
- Workerman `$conn->send($data)` → `$conn->send($data, $options)`（多一个 options 数组）
- Workerman `$conn->close()` → `$conn->close(1000, 'normal')`（增加 code/reason）

## 2. 从 Swoole 迁移

```php
// Swoole
$server = new Swoole\WebSocket\Server('0.0.0.0', 8080);
$server->on('open', fn($s, $req) => ...);
$server->on('message', fn($s, $frame) => $s->push($frame->fd, $data));
$server->start();
```

迁移：

```php
// kode/messaging
Messaging::server('ws://0.0.0.0:8080')
    ->withTransport('swoole')   // 显式选择 Swoole 传输
    ->on('connection.open', fn($c) => ...)
    ->on('message.received', fn($c, $m) => $c->send($m->payload()))
    ->start();
```

Swoole 协程写法可继续使用 `Coroutine::go()`，`kode/messaging` 会自动适配。

## 3. 从 Ratchet 迁移

Ratchet 风格：

```php
// Ratchet
$component = new MyComponent();
$app = new Ratchet\App('0.0.0.0', 8080);
$app->route('/ws', $component, ['*']);
$app->run();
```

迁移：

```php
Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', [$handler, 'onOpen'])
    ->on('message.received', [$handler, 'onMessage'])
    ->on('connection.close', [$handler, 'onClose'])
    ->start();
```

## 4. 从 php-amqplib 迁移

```php
// php-amqplib
$connection = new AMQPStreamConnection('host', 5672, 'user', 'pass');
$channel = $connection->channel();
$channel->queue_declare('hello');
$channel->basic_publish($msg, '', 'hello');
```

迁移：

```php
// kode/messaging (MQTT)
$client = Messaging::client('mqtt://host:1883')
    ->withCredentials('user', 'pass')
    ->connect();

$client->publish('hello', $data, ['qos' => 1]);
$client->subscribe('hello', fn($t, $p) => handle($p));
$client->loop();
```

> 注意：AMQP ≠ MQTT，迁移前确认业务可接受 MQTT。

## 5. 兼容性矩阵

| 框架 | 协议 | 迁移难度 | 备注 |
|---|---|---|---|
| Workerman | WS/TCP/UDP | 低 | 同 API 风格 |
| Swoole | WS/TCP/UDP | 中 | 用 `withTransport('swoole')` 保留协程 |
| Swow | WS/TCP/UDP | 中 | 用 `withTransport('swow')` |
| Ratchet | WS | 低 | 事件名微调 |
| php-amqplib | AMQP | 高 | 协议不同，业务可接受才迁 |
| mosquitto-php | MQTT | 低 | 几乎一对一 |
| EventSource 原生 | SSE | 极低 | 仅服务端迁移 |

## 6. 共存

`kode/messaging` 可与现有框架共存：

```php
// Laravel + Swoole 中，单独起一个 messaging 服务
Messaging::server('ws://0.0.0.0:9090')
    ->on('message.received', function ($c, $m) {
        // 调 Laravel 业务
        event(new MessageReceived($m->payload()));
    })
    ->start();
```

## 7. 性能对比（参考）

单核 4G 内存，1KB 消息 echo 场景：

| 框架 | 连接数 | QPS |
|---|---|---|
| Workerman | ~20K | 100K |
| Swoole | ~80K | 600K |
| Swow | ~80K | 600K |
| kode/messaging (stream) | ~5K | 30K |
| kode/messaging (swoole) | ~80K | 600K |

`kode/messaging` 的性能上限 = 所选传输层上限。
