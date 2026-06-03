# 协议扩展路线图

`kode/messaging` 的核心目标是**一个 API 覆盖所有长连接 / 实时消息协议**。本文档列出已经实现、即将落地、远期规划的协议矩阵及扩展方式。

## 1. 协议矩阵

| 协议 | 状态 | Scheme | 用途 | 协议规范 |
|---|---|---|---|---|
| WebSocket | ✅ 已实现 | `ws://` / `wss://` | 浏览器长连接、双向通信 | RFC 6455 |
| SSE | ✅ 已实现 | `sse://` / `https://` | 服务端推送、轻量流 | HTML5 |
| MQTT | ✅ 已实现 | `mqtt://` / `mqtts://` | IoT 设备、Pub/Sub | MQTT 3.1.1 / 5.0 |
| UDP | ✅ 已实现 | `udp://` | 实时音视频、组播广播 | RFC 768 |
| **Long-Polling** | 🟢 本版本新增 | `poll://` / `http://` | WebSocket 回退、低频推送 | HTTP/1.1 |
| **CoAP** | 🟢 本版本新增 | `coap://` / `coaps://` | IoT 传感器、低功耗 | RFC 7252 |
| NATS | 🟡 规划中 | `nats://` | 轻量 Pub/Sub、Service Mesh | NATS Protocol |
| STOMP | 🟡 规划中 | `stomp://` | 跨语言消息队列 | STOMP 1.2 |
| gRPC Streaming | 🟡 规划中 | `grpc://` | 微服务、流式 RPC | gRPC over HTTP/2 |
| QUIC | 🟠 远期 | `quic://` | HTTP/3、低延迟 WebSocket | RFC 9000 |
| WebTransport | 🟠 远期 | `webtransport://` | 浏览器双向流 | W3C Draft |
| RTMP | ⚪ 视情况 | `rtmp://` | 直播推流 | Adobe RTMP |
| AMQP 0.9.1 | ⚪ 委托 | — | RabbitMQ（已由 `kode/queue` 实现） | AMQP 0.9.1 |
| SMTP / IMAP | ❌ 不做 | — | 邮件协议非实时长连接 | — |
| FTP | ❌ 不做 | — | 短连接文件传输 | — |

## 2. 选型标准

新增协议必须满足以下至少一项：

- **场景独立**：与现有协议场景不重叠，覆盖新的应用领域
- **实现可行性**：纯 PHP 即可在 8.2+ 上实现，复杂协议（如 QUIC）允许可选依赖
- **生态需求**：社区有明确诉求（IoT、直播、Service Mesh）

不纳入：

- 已由 `kode/*` 家族其它包承担的协议（HTTP → `kode/http-client`、AMQP → `kode/queue`）
- 非长连接协议（HTTP 短请求、SMTP 等）
- 已有统治级 PHP 包且无差异化空间的协议

## 3. 扩展步骤

新增协议的标准流程（3 步）：

### 3.1 实现 Adapter

```php
namespace Kode\Messaging\Adapter\NewProtocol;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;

final class Server extends AbstractAdapter
{
    public static function scheme(): string { return 'newprotocol'; }
    public function version(): string { return 'rfc-xxx'; }
    public function listen(string $host, int $port): void { /* ... */ }
    public function connect(array $config): ConnectionInterface { /* ... */ }
    public function run(): void { /* 事件循环 */ }

    public static function autoRegister(): void
    {
        Registry::register('newprotocol', self::class);
    }
}
```

### 3.2 注册到 Messaging

在 `src/Messaging.php` 的 `normalizeScheme()` 中加入别名：

```php
in_array($scheme, ['newprotocol', 'newprotocols'], true) => 'newprotocol',
```

### 3.3 编写文档与测试

- `docs/newprotocol.md` 协议说明
- `tests/Unit/Adapter/NewProtocolTest.php` 单元测试
- `examples/newprotocol_server.php` 可运行示例

## 4. 协议覆盖建议

业务方按场景选择协议：

| 场景 | 推荐协议 | 备选 |
|---|---|---|
| Web 浏览器聊天 | WebSocket | Long-Polling |
| 服务端通知 | SSE | WebSocket |
| 移动端推送 | MQTT | WebSocket |
| IoT 传感器（弱网） | CoAP | MQTT |
| 实时音视频 | UDP | QUIC（未来） |
| 直播推流 | RTMP | WebTransport |
| 微服务流式 RPC | gRPC | WebSocket + Router |
| 跨语言事件总线 | NATS / STOMP | MQTT + Pub/Sub |

## 5. 协程与传输层

所有协议适配器最终落到同一传输层抽象：

| 驱动 | 协议 | 性能 | 依赖 |
|---|---|---|---|
| `StreamTransport` | 所有 | 基准 | 零依赖（内置） |
| `SocketTransport` | TCP/UDP | 提升 20-50% | ext-sockets |
| `SwooleTransport` | TCP/UDP | 100 倍 | ext-swoole |
| `SwowTransport` | TCP/UDP | 100 倍 | ext-swow |

传输层切换对业务代码透明，适配器在 `boot()` 时根据配置选择。

## 6. 版本节奏

| 版本 | 内容 |
|---|---|
| 1.0.0 | WebSocket / SSE / MQTT / UDP（首发） |
| **1.1.0** | **Long-Polling / CoAP**（本版本） |
| 1.2.0 | NATS / STOMP |
| 2.0.0 | gRPC Streaming / QUIC（破坏性 API 收敛） |
