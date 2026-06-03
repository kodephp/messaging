# WebTransport

> 适用：HTTP/3 双工通信、低延迟实时消息
> 方案：`wt://` / `webtransport://`
> 端口：默认 `4433`

WebTransport 是基于 **HTTP/3 + QUIC** 的现代双工协议，相比 WebSocket：

- 基于 QUIC（UDP），可绕过 TCP 中间盒
- 内置流（Stream）和数据报（Datagram）两种抽象
- 0-RTT 连接、连接迁移

> ⚠️ **浏览器原生 WebTransport 走 HTTP/3**。
> 完整 HTTP/3 + QUIC 实现需要专用后端（aioquic / msquic / quiche），
> 本包提供 **HTTP/2-fallback** + **业务抽象层**，业务可同时挂接：
>  - **fallback**（WebSocket / HTTP/2）— 用于本地开发与浏览器回退
>  - **原生**（外部 HTTP/3 后端）— 通过 `setBuilder()` / `dispatchBidirectional()` 等方法把外部事件投递给业务

## 三种流模型

| 类型 | 方向 | 用途 |
|---|---|---|
| **双向流 (Bidi)** | C ↔ S | 任意消息 |
| **单向流 (Unidi)** | C → S 或 S → C | 大文件 / 推流 |
| **Datagram (Dgram)** | C ↔ S（不可靠） | 实时音视频 / 游戏 |

## 业务层 API（与 transport 无关）

```php
use Kode\Messaging\Adapter\WebTransport\Server as WtServer;
use Kode\Messaging\Messaging;

$server = Messaging::server('wt://0.0.0.0:4433');

// 注册 Bidi / Unidi / Datagram 业务回调
$server->adapter()->onBidirectional('session-1', function ($payload, $meta) {
    // 处理客户端 → 服务端 Bidi 数据
});
$server->adapter()->onUnidirectional('session-1', function ($payload, $meta) {
    // 处理 Unidi
});
$server->adapter()->onDatagram('session-1', function ($payload, $meta) {
    // 处理 Datagram
});

$server->start();
```

## 客户端

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('wt://example.com:4433');
$conn = $client->connect();
$conn->sendBidirectional('hello');
$conn->sendUnidirectional('stream-data');
$conn->sendDatagram('ping', reliable: false);
```

## Datagram 协议标签

WebTransport Datagram 的首字节用于区分可靠 / 不可靠：

| 字节 | 含义 |
|---|---|
| `0x00` | 不可靠（不可重传） |
| `0x01` | 可靠（QUIC 内重传） |

包内 `WebTransportCodec::encodeDatagram()` / `decodeDatagram()` 提供该层编解码。

## HTTP/3 后端对接

如需挂接真实 aioquic / msquic，可在业务层：

1. 启动 aioquic 作为 HTTP/3 终结进程
2. 把 HTTP/3 接收到的 WebTransport 事件（按 stream id / datagram）转发到 kode/messaging
3. 业务回调通过 `$server->adapter()->onBidirectional($sessionId, $cb)` 注册

本包提供 `dispatchBidirectional()` / `dispatchUnidirectional()` / `dispatchDatagram()` 方法作为对接入口。

## 浏览器集成

```javascript
// 浏览器原生 WebTransport（需要 HTTP/3）
const transport = new WebTransport('https://example.com:4433/wt/test');
await transport.ready;
const stream = await transport.createBidirectionalStream();
const writer = stream.writable.getWriter();
await writer.write(new TextEncoder().encode('hello'));
```

## 配置项

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `subprotocol` | string | `wt-bidi` | 子协议 |
| `http3_backend` | string\|null | `null` | 外部 HTTP/3 后端地址（可选） |

## 安全建议

- 强制 TLS（`wts://`）
- Origin 校验（防止 CSRF）
- 业务层鉴权
