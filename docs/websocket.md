# WebSocket 协议指南

> 实现：RFC 6455
> 适用：浏览器双向通信、移动端长连接、实时游戏

## 1. 服务端

### 1.1 基础

```php
use Kode\Messaging\Messaging;

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', fn($c) => $c->send('welcome'))
    ->on('message.received', function ($c, $m) {
        $c->send("echo: {$m->payload()}");
    })
    ->start();
```

### 1.2 路由（基于 topic / event）

```php
$router = Messaging::router()
    ->on('chat.message',  ChatHandler::class)
    ->on('user.join',     JoinHandler::class)
    ->on('user.leave',    LeaveHandler::class);

Messaging::server('ws://0.0.0.0:8080')
    ->withRouter($router)
    ->on('message.received', function ($c, $m) use ($router) {
        $router->dispatch($c, $m);
    })
    ->start();
```

### 1.3 群组 / 房间

```php
$bus = Messaging::pubsub('redis');

// 加入房间
$conn->setAttribute('room', 'room-42');

// 收到消息后广播到房间
->on('message.received', function ($c, $m) use ($bus) {
    $room = $c->getAttribute('room');
    $bus->publish("room:$room", $m->payload());
});

// 跨节点订阅
$bus->subscribe('room:room-42', function ($payload) {
    // 推送给本节点所有 room-42 连接
});
```

### 1.4 鉴权

```php
use Kode\Messaging\Middleware\Auth\JwtAuthMiddleware;

Messaging::server('ws://0.0.0.0:8080')
    ->middleware(new JwtAuthMiddleware(env('JWT_SECRET')))
    ->on('message.received', fn($c, $m) => $c->send($m->payload()))
    ->start();
```

握手时客户端把 JWT 放在 `Sec-WebSocket-Protocol` 子协议或 query string 中。

### 1.5 限流

```php
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;

->middleware(new TokenBucketMiddleware(
    capacity: 100,           // 桶容量
    refillPerSecond: 60,     // 每秒补充
))
```

### 1.6 心跳

默认 30s 服务端 ping，60s 未响应关闭连接。

```php
->withHeartbeat(interval: 30, timeout: 60)
```

### 1.7 帧大小限制

```php
->withMaxFrameSize(2 * 1024 * 1024) // 2 MiB
```

### 1.8 二进制消息

```php
->on('message.received', function ($c, $m) {
    if ($m->isBinary()) {
        $bytes = $m->raw();     // 原始字节
        $c->send($bytes, ['binary' => true]);
    }
});
```

## 2. 客户端

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('ws://api.example.com')
    ->withHeader('Authorization', 'Bearer xxx')
    ->withProtocol('chat-v1')     // Sec-WebSocket-Protocol
    ->on('open',    function () use ($client) {
        $client->send(['event' => 'join', 'room' => '42']);
    })
    ->on('message', fn($m) => var_dump($m->payload()))
    ->on('close',   fn($c, $code, $reason) => log("closed: $code $reason"))
    ->on('error',   fn($e) => log($e->getMessage()))
    ->withReconnect(maxAttempts: 5, delayMs: 1000)
    ->connect();
```

### 2.1 断线重连

内置指数退避重连，$2^n \times 100ms$ 上限 30s。

### 2.2 心跳

```php
->withHeartbeat(interval: 15) // 客户端 ping
```

## 3. 集群模式

```php
use Kode\Messaging\Messaging;
use Kode\Process\Kode as Process;

Process::worker(Messaging::server('ws://0.0.0.0:8080'))
    ->count(4)
    ->withCluster(driver: 'redis', config: [
        'host' => '127.0.0.1',
        'port' => 6379,
    ])
    ->start();
```

集群内部自动：

- 节点注册到 Redis
- 跨节点广播
- 节点上下线通知

## 4. 与 Swoole / Swow 协作

```php
// 检测并启用
->withTransport(Messaging::detectBestTransport())
// 显式选择
->withTransport('swoole')
->withTransport('swow')
->withTransport('stream')  // 纯 PHP
```

性能参考（同机 8 核）：

| 驱动 | 连接数 | QPS | 内存 |
|---|---|---|---|
| stream | ~5K | 30K | 250MB |
| sockets | ~10K | 80K | 200MB |
| swoole | ~50K | 500K | 150MB |
| swow | ~50K | 500K | 150MB |

## 5. 完整示例：聊天室

```php
use Kode\Messaging\Messaging;

$bus = Messaging::pubsub('memory'); // 单机用 memory，集群用 redis

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', function ($conn) use ($bus) {
        $conn->setAttribute('joinedAt', time());
        $bus->publish('chat:system', [
            'type' => 'join',
            'id'   => $conn->id(),
            'time' => time(),
        ]);
    })
    ->on('message.received', function ($conn, $message) use ($bus) {
        $bus->publish('chat:room', [
            'id'      => $conn->id(),
            'payload' => $message->payload(),
            'time'    => microtime(true),
        ]);
    })
    ->on('connection.close', function ($conn) use ($bus) {
        $bus->publish('chat:system', [
            'type' => 'leave',
            'id'   => $conn->id(),
        ]);
    })
    ->withHeartbeat(30)
    ->start();
```

完整可运行示例：[docs/examples/chat.php](./examples/chat.php)

## 6. 常见问题

### 6.1 allowed_origins

生产环境必须明确允许的来源：

```php
->withAllowedOrigins(['https://app.example.com'])
```

默认 `*` 仅用于开发。

### 6.2 反向代理（Nginx）

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
}
```

### 6.3 大消息

默认 1 MiB，可调整：

```php
->withMaxFrameSize(10 * 1024 * 1024)
```

注意：超大消息应改用 HTTP 上传 + WebSocket 通知，不在长连接上传输大块。
