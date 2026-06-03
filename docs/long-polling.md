# Long-Polling 协议指南

Long-Polling（HTTP 长轮询）是**WebSocket 不可用时的回退方案**。客户端发起 HTTP 请求，服务端**保持连接打开**直到有数据可推送，然后立即返回。客户端收到响应后立即发起下一次请求，形成"半双工推送"。

本包将长轮询封装为协议无关的 `Connection` 抽象，业务代码可像使用 WebSocket 一样编写。

## 适用场景

| 场景 | 推荐 |
|---|---|
| 浏览器不支持 WebSocket | ✅ Long-Polling |
| 旧版 IE、企业代理 | ✅ Long-Polling |
| 极低频通知（1 分钟/次） | ✅ Long-Polling（资源占用极小） |
| 移动端弱网 | ✅ Long-Polling + 重试 |
| 高频双向通信 | ❌ 用 WebSocket |
| 实时音视频 | ❌ 用 WebRTC / QUIC |

## URL Scheme

| Scheme | 协议 |
|---|---|
| `poll://` | 短别名 |
| `long-polling://` | 全名 |
| `lp://` | 短别名 |
| `http://` / `https://` | 标准 HTTP（视场景） |

默认端口：**8083**

## 服务端

```php
use Kode\Messaging\Messaging;

Messaging::server('poll://0.0.0.0:8083')
    ->on('connection.open', function ($conn) {
        // 客户端连接已建立（HTTP 握手完成）
    })
    ->on('message.received', function ($conn, $message) {
        $method = $message->headers()['coap.method'] ?? null; // long-polling 不涉及
        $topic  = $message->topic();  // query 中 topic=xxx
        $body   = $message->payload();

        // 业务处理后回写响应
        $conn->send(['echo' => $body, 'topic' => $topic]);
    })
    ->on('connection.close', function ($conn) {
        // 客户端断开或 hold 超时
    })
    ->start();
```

### 行为说明

- 单次 `send()` 写入即关闭连接，客户端必须发起下一次请求
- 超过 `hold_timeout_ms`（默认 25 秒）自动返回 `204 No Content`
- 支持 `GET`、`POST`、`PUT`、`DELETE`、`OPTIONS`
- 支持 `Content-Length` 与 `Transfer-Encoding: chunked` 响应
- 支持 CORS 预检（`OPTIONS` 直接 204）

### 配置项

```php
'long-polling' => [
    'host'             => '0.0.0.0',
    'port'             => 8083,
    'max_connections'  => 10_000,
    'hold_timeout_ms'  => 25_000,         // 单次 hold 最长（建议 ≥ 客户端超时）
    'read_timeout'     => 30,             // 读请求超时
    'max_body_size'    => 1_048_576,      // 1 MiB
    'cors'             => true,
    'allowed_origins'  => ['*'],
    'ping'             => true,           // 启用 GET /ping
],
```

## 客户端

```php
use Kode\Messaging\Messaging;

$conn = Messaging::client('poll://api.example.com/sync?topic=orders')
    ->withMethod('POST')
    ->withHeader('X-Token', 'xxx')
    ->withBody(json_encode(['since' => time() - 60]))
    ->onMessage(function ($msg) {
        // 处理服务端响应
        $payload = $msg->payload();        // 已自动 JSON 解码
        $status  = $msg->headers()['coap.status'] ?? 200; // long-polling 不涉及
        // ...
    })
    ->onError(function ($e) {
        // 网络错误，自动重试
    })
    ->connect();

$conn->poll();   // 持续轮询主循环
```

### 行为说明

- 每次 `poll()` 内部完成"建连 → 请求 → 响应 → 关闭"完整周期
- 异常后按 `retry_delay_ms`（默认 1s）退避重试
- 达到 `max_retries`（默认 0=无限）后抛异常
- `stop()` 可主动停止循环
- 支持 chunked 响应与 Content-Length 响应

## 协议消息结构

请求 → 服务端：

```
POST /sync?topic=orders HTTP/1.1
Host: api.example.com:8083
Content-Type: application/json
Content-Length: 17

{"since":1700000000}
```

响应 ← 服务端：

```
HTTP/1.1 200 OK
Content-Type: application/json
X-Connection-Id: lp-abc123
Connection: close
Content-Length: 27

{"orders":[{"id":1}]}
```

## 与 WebSocket 互转

业务可以**根据环境**选择协议：

```php
$scheme = $clientSupportsWebSocket ? 'ws://api.example.com' : 'poll://api.example.com';
$conn   = Messaging::client($scheme)->connect();
$conn->onMessage(fn($msg) => handle($msg->payload()));
```

`ConnectionInterface` 屏蔽协议差异，业务代码无需修改。

## 与 WebSocket 互转的局限

| 维度 | Long-Polling | WebSocket |
|---|---|---|
| 单连接延迟 | 100-500ms（每次新建） | < 10ms |
| 服务端推送 | 半双工 | 全双工 |
| 鉴权 / Cookie | ✅ | ✅ |
| 二进制帧 | ✅ | ✅ |
| 流量 | 每次请求+响应头开销大 | 长连接开销小 |

## 故障排查

| 现象 | 排查 |
|---|---|
| 客户端立即重连 | `hold_timeout_ms` 太小，或服务端未保持 |
| 服务端空响应 | 业务未调 `$conn->send()`，hold 超时后 204 |
| 请求被拒 | `max_connections` 已满，503 |
| CORS 错误 | `allowed_origins` 未包含来源 |
| 大请求被拒 | 超过 `max_body_size`，413 |

## 性能数据

单 Worker 压测（本地 loopback，PHP 8.3，4 核）：

| 模式 | 吞吐 |
|---|---|
| Long-Polling（10K 连接，5s 间隔） | ~2,000 req/s |
| Long-Polling（1K 连接，1s 间隔） | ~1,000 req/s |
| WebSocket（10K 连接） | ~50,000 msg/s |

> 实际数值与 payload 大小、网络延迟、JVM/GC 行为相关。生产环境强烈建议配合 Swoole / Swow 协程传输层。
