# SSE 协议指南

> 实现：HTML5 Server-Sent Events
> 适用：服务端推送、实时通知、轻量流、HTTP-only 场景

## 1. 简介

SSE（Server-Sent Events）是基于 HTTP 的单向推送协议，浏览器通过原生 `EventSource` API 即可使用。相比 WebSocket：

| 特性 | SSE | WebSocket |
|---|---|---|
| 方向 | 单向（服务器→客户端） | 双向 |
| 协议 | HTTP | upgrade |
| 重连 | 浏览器自动 | 需手动 |
| 鉴权 | 标准 HTTP | 握手 |
| 适用 | 通知、股票、大屏 | 聊天、游戏 |

## 2. 服务端

### 2.1 基础

```php
use Kode\Messaging\Messaging;

Messaging::server('sse://0.0.0.0:8081')
    ->on('connection.open', function ($conn) {
        $conn->send(['event' => 'connected', 'data' => ['id' => $conn->id()]]);
    })
    ->interval(1000)  // 每秒推送一次
    ->on('interval', function ($conn) {
        $conn->send([
            'event' => 'tick',
            'data'  => ['time' => time()],
        ]);
    })
    ->start();
```

### 2.2 HTTP 方式（与 kode/http 协作）

```php
use Kode\Http\Kode as Http;

Http::router()
    ->get('/stream', function ($request, $response) {
        $emitter = Messaging::emitter('sse');
        return $emitter->respond($response); // 返回符合 PSR-7 的 Response
    });
```

两种模式自动选择：直接 `Messaging::server('sse://...')` 启动独立端口；`emitter()` 模式嵌入已有 HTTP 服务。

### 2.3 鉴权

SSE 走 HTTP，鉴权用标准方式：

```php
->middleware(new BearerAuthMiddleware(env('SSE_SECRET')))
```

客户端：

```javascript
const es = new EventSource('/stream?token=xxx');
// 或在 header 中（EventSource 不支持自定义 header，需用 ?token=）
```

### 2.4 重连控制

```php
->withRetry(5000) // 客户端断线 5s 后重连
```

事件格式：

```
retry: 5000\n\n
```

### 2.5 多事件类型

```php
$conn->send([
    'event' => 'order.created',
    'id'    => 'evt-1001',
    'data'  => ['orderId' => 1001, 'amount' => 999],
]);
```

浏览器：

```javascript
const es = new EventSource('/stream');
es.addEventListener('order.created', (e) => {
    const data = JSON.parse(e.data);
    console.log('New order:', data);
});
```

## 3. 客户端

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('sse://api.example.com/stream')
    ->withHeader('Authorization', 'Bearer xxx')
    ->on('event.tick',       fn($m) => print_r($m->payload()))
    ->on('event.order.created', fn($m) => handleOrder($m->payload()))
    ->on('error',            fn($e) => log($e->getMessage()))
    ->withReconnect(5, 1000)
    ->connect();
```

## 4. 高频推送

SSE 不适合 60Hz 高频，但适合亚秒级（200ms+）推送。

```php
->interval(200) // 5 Hz
```

如需更高频，请改用 WebSocket。

## 5. 长连接保持

```php
->withKeepalive(15) // 每 15s 发送注释心跳
```

浏览器 EventSource 看不到注释心跳，但能维持代理连接。

## 6. 集群模式

```php
use Kode\Messaging\Messaging;
use Kode\Process\Kode as Process;

Process::worker(Messaging::server('sse://0.0.0.0:8081'))
    ->count(2)
    ->withCluster(driver: 'redis')
    ->start();

// 业务代码中发布
$bus = Messaging::pubsub('redis');
$bus->publish('notifications', ['userId' => 42, 'text' => 'hi']);
```

## 7. 反向代理

Nginx：

```nginx
location /sse {
    proxy_pass http://127.0.0.1:8081;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 86400;
    add_header Cache-Control no-cache;
    add_header X-Accel-Buffering no;
}
```

`proxy_buffering off` 关键，否则 Nginx 会缓冲导致延迟。

## 8. 完整示例：实时通知

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\PubSub\RedisBus;

$bus = new RedisBus(['host' => '127.0.0.1']);

Messaging::server('sse://0.0.0.0:8081')
    ->on('connection.open', function ($conn) use ($bus) {
        $userId = $conn->getAttribute('userId');
        $bus->subscribe("notify:$userId", function ($payload) use ($conn) {
            $conn->send(['event' => 'notify', 'data' => $payload]);
        });
    })
    ->start();

// 业务侧发布
$bus->publish("notify:42", [
    'title' => '新消息',
    'body'  => '你有一条新消息',
    'time'  => time(),
]);
```

完整可运行示例：[docs/examples/push.php](./examples/push.php)
