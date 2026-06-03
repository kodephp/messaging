# 架构设计

## 1. 分层模型

```
┌────────────────────────────────────────────────────────┐
│  Layer 5 — 应用层（用户代码）                            │
│           Business handlers, on('message.received', …)  │
├────────────────────────────────────────────────────────┤
│  Layer 4 — 中间件管道（Middleware Pipeline）             │
│           Auth → RateLimit → Codec → Business          │
├────────────────────────────────────────────────────────┤
│  Layer 3 — 协议适配器（Adapter）                         │
│           WebSocket / SSE / MQTT 适配器                  │
├────────────────────────────────────────────────────────┤
│  Layer 2 — 消息抽象（Message / Connection）              │
│           MessageInterface, ConnectionInterface         │
├────────────────────────────────────────────────────────┤
│  Layer 1 — 传输层（Transport）                           │
│           PHP stream / Swoole / Swow / ext-sockets      │
└────────────────────────────────────────────────────────┘
```

每一层只依赖下一层的接口，**协议适配器** 是整个体系的核心扩展点。

## 2. 核心接口

### 2.1 MessageInterface

协议无关的消息体：

```php
namespace Kode\Messaging\Contract;

interface MessageInterface
{
    public function id(): string;
    public function event(): ?string;
    public function topic(): ?string;       // MQTT 用
    public function payload(): mixed;       // 原始载荷（已解码）
    public function raw(): string;          // 原始字节
    public function headers(): array;
    public function qos(): int;             // MQTT QoS 0/1/2
    public function isBinary(): bool;
    public function withPayload(mixed $payload): self;
}
```

### 2.2 ConnectionInterface

协议无关的连接：

```php
namespace Kode\Messaging\Contract;

interface ConnectionInterface
{
    public function id(): string;
    public function protocol(): string;     // websocket|sse|mqtt
    public function remoteAddress(): string;
    public function send(mixed $payload, array $options = []): bool;
    public function close(int $code = 1000, string $reason = ''): void;
    public function isOpen(): bool;
    public function attributes(): array;    // 业务附加属性
    public function setAttribute(string $key, mixed $value): void;
    public function getAttribute(string $key, mixed $default = null): mixed;
}
```

### 2.3 AdapterInterface

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

新增协议只需实现 `AdapterInterface`，注册到 `AdapterRegistry` 即可。

## 3. 中间件管道

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

## 4. 事件模型

通过 `kode/event` 派发（未安装时使用内部 `SimpleDispatcher`）：

| 事件 | 触发 |
|---|---|
| `server.start` / `server.stop` | 生命周期 |
| `connection.open` / `connection.close` | 连接 |
| `message.received` / `message.sent` | 消息 |
| `error.protocol` / `error.codec` / `error.transport` | 错误 |

事件命名使用点分小写，可被 `kode/aop` 拦截。

## 5. 传输层抽象

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

## 6. 集群与分布式

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

## 7. 协程模型

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

## 8. 配置与生命周期

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

## 9. 错误与降级

| 错误 | 处理 |
|---|---|
| 协议帧错误 | 关闭连接 + `error.protocol` 事件 |
| 中间件抛异常 | 业务层 catch + 发送错误帧 + 记录 |
| Transport 断开 | 等待重连 + 派发 `connection.close` |
| 客户端超过 max | 拒绝握手 + 429 |

所有错误**绝不** `die()`，保证服务可观测、可恢复。
