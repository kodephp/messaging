# 架构设计

> 本文档面向**包维护者**与**进阶使用者**。普通用户请先阅读 [quick-start.md](./quick-start.md)。

## 1. 设计哲学

`kode/messaging` 的核心目标只有一句话：

> **让业务代码面向 `MessageInterface` 编程，不感知任何具体协议。**

围绕这一目标，我们定义了五个设计原则：

1. **统一入口**：所有协议通过 `Messaging::server($scheme)->...->start()` 启动。
2. **协议无关**：业务层只调用 `MessageInterface` / `ConnectionInterface`，适配器负责转换。
3. **可插拔**：新增协议只需实现 `AdapterInterface` 并注册到 `Registry`。
4. **渐进增强**：核心包零强制依赖；按需开启扩展。
5. **生产就绪**：错误可恢复、信号可优雅退出、配置可热加载。

## 2. 分层模型

```
┌────────────────────────────────────────────────────────┐
│  Layer 5 — 应用层（用户代码）                            │
│           Business handlers, on('message.received', …)  │
├────────────────────────────────────────────────────────┤
│  Layer 4 — 中间件管道（Middleware Pipeline）             │
│           Auth → RateLimit → Codec → Business          │
├────────────────────────────────────────────────────────┤
│  Layer 3 — 协议适配器（Adapter）                         │
│           ws / sse / mqtt / udp / longpolling / coap   │
│           nats / stomp / grpc / wt / rtmp              │
├────────────────────────────────────────────────────────┤
│  Layer 2 — 消息抽象（Message / Connection）              │
│           MessageInterface, ConnectionInterface         │
├────────────────────────────────────────────────────────┤
│  Layer 1 — 传输层（Transport）                           │
│           PHP stream / Swoole / Swow / ext-sockets      │
├────────────────────────────────────────────────────────┤
│  Layer 0 — 运行时支撑                                    │
│           Logger (PSR-3) / EventDispatcher (PSR-14)     │
│           Cluster / PubSub / Fibers (kode/*)            │
└────────────────────────────────────────────────────────┘
```

每一层只依赖下一层的接口，**协议适配器** 是整个体系的核心扩展点。

## 3. 协议矩阵

| Scheme | 协议 | 服务端 | 客户端 | 适配器位置 | 默认端口 |
|---|---|---|---|---|---|
| `ws` / `wss` | WebSocket（RFC 6455） | ✓ | ✓ | `Adapter/WebSocket` | 8080 |
| `sse` | Server-Sent Events | ✓ | ✓ | `Adapter/Sse` | 8081 |
| `mqtt` | MQTT 3.1.1 / 5.0 | — | ✓ | `Adapter/Mqtt` | 1883 |
| `udp` | UDP / Datagram（RFC 768） | ✓ | ✓ | `Adapter/Udp` | 8082 |
| `longpolling` / `lp` | Long-Polling（HTTP/1.1） | ✓ | ✓ | `Adapter/LongPolling` | 8083 |
| `coap` / `coaps` | CoAP（RFC 7252） | ✓ | ✓ | `Adapter/Coap` | 5683 |
| `nats` | NATS 2.0 Pub/Sub | — | ✓ | `Adapter/Nats` | 4222 |
| `stomp` | STOMP 1.2 | — | ✓ | `Adapter/Stomp` | 61613 |
| `grpc` | gRPC Streaming | ✓ | ✓ | `Adapter/Grpc` | 50051 |
| `wt` | WebTransport HTTP/3 | ✓ | ✓ | `Adapter/WebTransport` | 4433 |
| `rtmp` | RTMP（直播） | ✓ | — | `Adapter/Rtmp` | 1935 |

> 全部适配器通过 `Kode\Messaging\Adapter\Registry::register()` 自注册到 `Messaging::server($scheme)` / `client($scheme)`。

## 4. 核心接口

### 4.1 MessageInterface

协议无关的消息体：

```php
namespace Kode\Messaging\Contract;

interface MessageInterface
{
    public function id(): string;
    public function event(): ?string;
    public function topic(): ?string;       // MQTT / NATS / STOMP / CoAP 用
    public function payload(): mixed;       // 原始载荷（已解码）
    public function raw(): string;          // 原始字节
    public function headers(): array;
    public function qos(): int;             // MQTT QoS 0/1/2
    public function isBinary(): bool;
    public function withPayload(mixed $payload): self;
}
```

### 4.2 ConnectionInterface

协议无关的连接：

```php
namespace Kode\Messaging\Contract;

interface ConnectionInterface
{
    public function id(): string;
    public function protocol(): string;     // websocket|sse|mqtt|...
    public function remoteAddress(): string;
    public function send(mixed $payload, array $options = []): bool;
    public function close(int $code = 1000, string $reason = ''): void;
    public function isOpen(): bool;
    public function attributes(): array;    // 业务附加属性
    public function setAttribute(string $key, mixed $value): void;
    public function getAttribute(string $key, mixed $default = null): mixed;
}
```

### 4.3 AdapterInterface

所有协议适配器实现统一接口：

```php
namespace Kode\Messaging\Adapter;

interface AdapterInterface
{
    public static function scheme(): string;        // ws|sse|mqtt
    public function boot(array $config): void;
    public function listen(string $host, int $port): void;
    public function connect(array $config): ConnectionInterface;
    public function shutdown(): void;
}
```

新增协议只需：

1. 创建 `src/Adapter/MyProtocol/Server.php` 实现 `AdapterInterface`；
2. 在 `register.php` 中 `Registry::register('myproto', MyServer::class)`；
3. 业务代码调用 `Messaging::server('myproto://...')` 即可。

## 5. 适配器职责拆解

| 协议 | 入口类 | 编解码 | 连接 | 文档 |
|---|---|---|---|---|
| WebSocket | `Adapter\WebSocket\Server` | `Codec\Frame` / `Codec\Handshake` | `WebSocketConnection` | [websocket.md](./websocket.md) |
| SSE | `Adapter\Sse\Server` | `Formatter` | `SseConnection` | [sse.md](./sse.md) |
| MQTT | `Adapter\Mqtt\Client` | `Packet\Codec` | `MqttConnection` | [mqtt.md](./mqtt.md) |
| UDP | `Adapter\Udp\Server` | — | `UdpConnection` | [udp.md](./udp.md) |
| Long-Polling | `Adapter\LongPolling\Server` | — | `LongPollingConnection` + `Hub` | [long-polling.md](./long-polling.md) |
| CoAP | `Adapter\Coap\Server` | `CoapPacket` / `CoapBlock` | `CoapConnection` | [coap.md](./coap.md) |
| NATS | `Adapter\Nats\Client` | `NatsCodec` | `NatsConnection` | [nats.md](./nats.md) |
| STOMP | `Adapter\Stomp\Client` | `StompCodec` | `StompConnection` | [stomp.md](./stomp.md) |
| gRPC | `Adapter\Grpc\Server` | `GrpcCodec` | `GrpcConnection` | [grpc.md](./grpc.md) |
| WebTransport | `Adapter\WebTransport\Server` | `WebTransportCodec` | `WebTransportConnection` | [webtransport.md](./webtransport.md) |
| RTMP | `Adapter\Rtmp\Server` | `RtmpChunk` / `Amf0` | `RtmpConnection` | [rtmp.md](./rtmp.md) |

## 6. 中间件管道

```php
namespace Kode\Messaging\Middleware;

interface MiddlewareInterface
{
    public function process(MessageInterface $message, callable $next): MessageInterface;
}
```

中间件按注册顺序组成洋葱圈：

```
Request → Auth → RateLimit → Codec → Business → Codec → send
```

内置中间件（`src/Middleware/`）：

| 命名空间 | 作用 |
|---|---|
| `Auth\BearerAuthMiddleware` | Bearer Token 鉴权 |
| `RateLimit\TokenBucketMiddleware` | 令牌桶限流 |
| `Codec\JsonCodec` | JSON 编解码 |
| `Codec\RawCodec` | 透传原始字节 |

## 7. 事件模型

通过 `kode/event` 派发（未安装时使用内部 `Event\Dispatcher`）：

| 事件 | 触发 |
|---|---|
| `server.start` / `server.stop` | 生命周期 |
| `server.error` | 全局错误 |
| `connection.open` / `connection.close` | 连接 |
| `message.received` / `message.sent` | 消息 |
| `error.protocol` / `error.codec` / `error.transport` | 错误 |
| `cluster.message` | 跨节点消息 |

事件命名使用点分小写，可被 `kode/aop` 拦截。

订阅示例：

```php
use Kode\Messaging\Messaging;

$bus = Messaging::pubsub('memory');

Messaging::server('ws://0.0.0.0:8080')
    ->on('message.received', function ($conn, $msg) use ($bus) {
        $bus->publish('chat', $msg->payload());
    });
```

## 8. 传输层抽象

`Transport\TransportInterface` 屏蔽底层 socket：

```php
interface TransportInterface
{
    public function accept(): ?ConnectionInterface;
    public function connect(string $host, int $port, array $options = []): ConnectionInterface;
    public function close(): void;
}
```

内置实现：

| 驱动 | 适用 |
|---|---|
| `StreamTransport` | 纯 PHP `stream_socket_server`，零扩展依赖 |
| `SocketTransport` | `ext-sockets`，更高性能 |
| `SwooleTransport` | Swoole 协程（可选） |
| `SwowTransport` | Swow 协程（可选） |

通过 `config('transport')` 一键切换：`auto` / `stream` / `sockets` / `swoole` / `swow`。

## 9. 集群与分布式

```
┌──────────┐      ┌──────────┐      ┌──────────┐
│ Node A   │      │ Node B   │      │ Node C   │
│ Worker 1 │      │ Worker 1 │      │ Worker 1 │
│ Worker 2 │      │ Worker 2 │      │ Worker 2 │
└────┬─────┘      └────┬─────┘      └────┬─────┘
     │                 │                 │
     └─────────────────┼─────────────────┘
                       │
                ┌──────▼──────┐
                │ Channel/Redis│  ← 跨节点 Pub/Sub
                └─────────────┘
```

- **节点内**：通过 `kode/process\Channel\Server`（共享内存 / TCP）广播
- **跨节点**：通过 Redis Pub/Sub 或自实现 `DistributedBusInterface`

详见 [docs/pubsub.md](./pubsub.md)。

## 10. 协程模型

```php
// 与 kode/fibers 协作
use Kode\Fibers\Coroutine;

$builder->on('message.received', function ($conn, $message) {
    Coroutine::go(function () use ($conn, $message) {
        $data = Coroutine::await(fetchFromApi($message->payload()));
        $conn->send($data);
    });
});
```

无 Fiber 环境（理论上 PHP 8.1+ 都有），自动降级为顺序执行。

## 11. CLI 工具架构

`bin/messaging` 是纯 PHP CLI（无外部依赖），结构如下：

```
messaging <command> [options]
   │
   ├─ version / info        →  打印版本 + 运行时信息
   ├─ protocols             →  遍历 Registry::schemes()
   ├─ self-check            →  自检 PHP 版本、扩展、autoload、协议注册
   ├─ config [section]      →  require config/messaging.php 后 dump
   ├─ doc [name]            →  查找 docs/ 下文件并通过 PAGER 输出
   ├─ start <file|--protocol=...>
   │       └─ 内置协议服务：直接 new Adapter\Xxx\Server + start
   │       └─ 自定义文件：require_once 用户的启动文件
   ├─ worker <file> --count=N
   │       └─ 占位（依赖 kode/process）
   └─ stop / reload / status
           └─ 占位（依赖 kode/process）
```

CLI 通过 `Messaging::defaultPort($protocol)` 知道每个协议默认监听端口，因此 `--protocol=ws` 无需额外参数即可启动。

## 12. 配置与生命周期

```php
Messaging::server('ws://0.0.0.0:8080', [
    'websocket' => [
        'max_frame_size' => 1_048_576,
        'allowed_origins' => ['https://app.example.com'],
        'heartbeat' => 30,
    ],
])
->boot()           // 初始化（不阻塞）
->middleware(...)
->on(...)
->start();         // 阻塞主循环
```

`start()` 内部：

1. 实例化 `Adapter`（按 scheme 选择）
2. 实例化 `Transport`（按可用扩展）
3. 注册 `Connection` → 事件 → `Middleware`
4. 进入事件循环
5. SIGTERM 优雅退出（`stop()`）

## 13. 错误与降级

| 错误 | 处理 |
|---|---|
| 协议帧错误 | 关闭连接 + `error.protocol` 事件 |
| 中间件抛异常 | 业务层 catch + 发送错误帧 + 记录 |
| Transport 断开 | 等待重连 + 派发 `connection.close` |
| 客户端超过 max | 拒绝握手 + 429 |
| PHP 致命错误 | `set_error_handler` 转 `MessagingException` |

所有错误**绝不** `die()`，保证服务可观测、可恢复。

## 14. 扩展点清单

| 扩展点 | 方式 | 接口 |
|---|---|---|
| 新协议 | 实现 `AdapterInterface` + `Registry::register` | `Adapter\AdapterInterface` |
| 新鉴权 | 实现 `MiddlewareInterface` | `Middleware\MiddlewareInterface` |
| 新编解码 | 实现 `MiddlewareInterface` | `Middleware\MiddlewareInterface` |
| 新总线驱动 | 实现 `BusInterface` | `PubSub\BusInterface` |
| 新事件订阅 | 实现 `EventSubscriberInterface` | `Event\EventSubscriberInterface` |
| 新传输 | 实现 `TransportInterface` | `Transport\TransportInterface` |
| 新 CLI 子命令 | 扩展 `bin/messaging` | — |

## 15. 性能与可伸缩

| 维度 | 策略 |
|---|---|
| 单进程 | PHP 8.2+ 无锁 stream；UDP/CoAP 单机 10w+ QPS |
| 多 Worker | `kode/process` 接管，零侵入 |
| 跨节点 | Redis Pub/Sub 背板 |
| 协程 | `kode/fibers` 协程化阻塞调用 |
| 背压 | `tuning.max_outbound_queue` 阈值限制 |

## 16. 兼容性策略

| PHP 版本 | 启用特性 | 关闭特性（保持兼容） |
|---|---|---|
| 8.2 | readonly class、DNF types、true/false/null standalone | typed class const、property hooks、pipe operator |
| 8.3 | typed const、`#[\Override]`、`Random\Randomizer` | property hooks、pipe operator |
| 8.4 | property hooks、asymmetric visibility、`#[\Deprecated]` | pipe operator |
| 8.5 | pipe operator `\|>`、persistent cURL | — |

所有 8.3+ 特性均通过 `Support\PhpCompat::isPhp8X()` 运行时判断，**包内代码在 8.2 下完全可运行**。
