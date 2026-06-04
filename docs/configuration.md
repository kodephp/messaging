# 配置

> `kode/messaging` 的所有配置可通过 `config/messaging.php` 集中管理，也可在调用 `Messaging::server()` / `Messaging::client()` 时按需覆盖。

## 1. 配置文件位置

```
项目根/
├── config/
│   └── messaging.php   ← 业务配置
└── ...
```

可通过 `php bin/messaging config [section]` 随时查看生效值：

```bash
$ php bin/messaging config websocket
[websocket]
  host: 0.0.0.0
  port: 8080
  ...
```

## 2. 完整配置项

### 2.1 顶层

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `default` | string | `ws` | 默认协议 scheme |
| `logger` | `Psr\Log\LoggerInterface\|null` | `null` | 日志接口 |
| `event_dispatcher` | `Psr\EventDispatcher\EventDispatcherInterface\|null` | `null` | PSR-14 事件派发器 |
| `transport` | string | `auto` | `auto` / `stream` / `sockets` / `swoole` / `swow` |

### 2.2 WebSocket (`websocket`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 监听地址 |
| `port` | `8080` | 监听端口 |
| `max_frame_size` | `1_048_576` | 单帧最大字节（1 MiB） |
| `max_connections` | `10_000` | 最大并发连接 |
| `allowed_origins` | `['*']` | Origin 白名单（生产必须明确指定） |
| `heartbeat_interval` | `30` | 心跳间隔（秒） |
| `heartbeat_timeout` | `60` | 心跳超时（秒） |
| `handshake_timeout` | `10` | 握手超时（秒） |
| `subprotocols` | `[]` | 支持的子协议 |
| `enable_compression` | `false` | permessage-deflate |
| `enable_binary` | `true` | 是否支持二进制帧 |

### 2.3 SSE (`sse`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 监听地址 |
| `port` | `8081` | 监听端口 |
| `retry_ms` | `3000` | 客户端重连间隔 |
| `keepalive_seconds` | `15` | 心跳 |
| `max_connections` | `10_000` | 最大并发 |
| `heartbeat_event` | `ping` | 心跳事件名 |
| `enable_cors` | `true` | 是否允许跨域 |

### 2.4 MQTT (`mqtt`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `127.0.0.1` | Broker 主机 |
| `port` | `1883` | Broker 端口 |
| `version` | `3.1.1` | `3.1.1` / `5.0` |
| `keepalive` | `60` | 心跳（秒） |
| `clean_session` | `true` | 是否清理会话 |
| `max_inflight` | `1000` | QoS 1/2 飞行窗口 |
| `max_packet_size` | `268_435_456` | 单包最大字节（256 MiB） |
| `auto_reconnect` | `true` | 自动重连 |
| `session.driver` | `memory` | `memory` / `redis` / `apcu` |
| `tls.cafile` | `null` | TLS CA 证书 |
| `tls.local_cert` / `local_pk` | `null` | 客户端证书 / 私钥 |
| `tls.verify_peer` | `true` | 严格校验对端证书 |

### 2.5 UDP (`udp`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 监听地址 |
| `port` | `8082` | 监听端口 |
| `max_packet_size` | `65_507` | UDP 单包最大载荷 |
| `enable_broadcast` | `true` | 允许广播 |
| `enable_multicast` | `true` | 允许组播 |
| `socket_timeout` | `30` | 套接字超时（秒，0=阻塞） |

### 2.6 Long-Polling (`long-polling`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 监听地址 |
| `port` | `8083` | 监听端口 |
| `max_connections` | `10_000` | 最大并发 |
| `hold_timeout_ms` | `25_000` | 单次 hold 最长（毫秒） |
| `read_timeout` | `30` | 读超时（秒） |
| `max_body_size` | `1_048_576` | 单请求体最大 |
| `cors` | `true` | 是否允许跨域 |
| `allowed_origins` | `['*']` | Origin 白名单 |
| `ping` | `true` | GET `/ping` 探活 |

### 2.7 CoAP (`coap`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 监听地址 |
| `port` | `5683` | 监听端口 |
| `max_packet_size` | `1_152` | RFC 7252 链路 MTU 建议 |
| `ack_timeout_ms` | `2_000` | CON 超时 |
| `max_retransmit` | `4` | 最大重传次数 |
| `retransmit_backoff` | `2.0` | 重传退避因子 |
| `enable_observe` | `true` | RFC 7641 订阅推送 |
| `default_response_format` | `50` | 默认响应格式（application/json） |

### 2.8 NATS (`nats`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | Broker 主机 |
| `port` | `4222` | Broker 端口 |
| `name` | `kode-messaging` | 客户端标识 |
| `pedantic` | `false` | 严格模式 |
| `verbose` | `false` | 详细日志 |
| `ping_interval` | `30` | 心跳（秒，0 关闭） |
| `max_payload` | `1_048_576` | 单消息最大字节 |
| `auth.token` / `auth.user` / `auth.password` | `null` | NATS 鉴权 |
| `tls.*` | — | TLS 配置（同 MQTT） |

### 2.9 STOMP (`stomp`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | Broker 主机 |
| `port` | `61613` | Broker 端口 |
| `version` | `1.2` | `1.0` / `1.1` / `1.2` |
| `login` / `passcode` | `null` | 用户名 / 密码 |
| `client_id` | `null` | 客户端标识 |
| `heartbeat_ms` | `10_000` | 心跳（毫秒） |
| `heartbeat_zero` | `0,0` | 关闭心跳值 |
| `default_destination` | `/queue/default` | 默认 destination |

### 2.10 gRPC (`grpc`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 主机 |
| `port` | `50051` | 端口 |
| `tls` | `false` | 是否使用 TLS |
| `timeout` | `5.0` | Unary 超时（秒） |
| `max_message_size` | `4 MiB` | 单帧最大 |
| `user_agent` | `kode-messaging/grpc` | UA |
| `default_authority` | `null` | 默认 Authority 头 |

### 2.11 WebTransport (`webtransport`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 主机 |
| `port` | `4433` | 端口 |
| `subprotocol` | `wt-bidi` | `wt-bidi` / `wt-unidi` / `wt-dgram` |
| `http3_backend` | `null` | 外部 HTTP/3 后端地址 |
| `datagram_reliable` | `false` | Datagram 默认不可靠 |

### 2.12 RTMP (`rtmp`)

| 项 | 默认 | 说明 |
|---|---|---|
| `host` | `0.0.0.0` | 主机 |
| `port` | `1935` | 端口 |
| `chunk_size` | `4096` | 出向 chunk |
| `window_ack_size` | `2_500_000` | Window ACK size |
| `peer_bandwidth` | `2_500_000` | 对端带宽 |
| `app` | `live` | 默认 app |
| `chunk_buffer_size` | `65_536` | chunk 缓冲 |

### 2.13 PubSub (`pubsub`)

| 项 | 默认 | 说明 |
|---|---|---|
| `default` | `memory` | `memory` / `channel` / `redis` |
| `redis.host` / `port` / `db` | `127.0.0.1` / `6379` / `0` | Redis 配置 |
| `redis.prefix` | `messaging:` | Key 前缀 |
| `channel.driver` | `kode-process` | 跨进程通道驱动 |

### 2.14 Cluster (`cluster`)

| 项 | 默认 | 说明 |
|---|---|---|
| `enabled` | `false` | 是否启用 |
| `driver` | `redis` | `redis` / `channel` |
| `node_id` | 自动 | 当前节点 ID |
| `heartbeat` | `5` | 心跳（秒） |

### 2.15 Tuning (`tuning`)

| 项 | 默认 | 说明 |
|---|---|---|
| `worker_count` | `1` | Worker 进程数（kode/process 接管） |
| `use_fibers` | `true` | 协程开关 |
| `send_buffer_size` | `65_536` | 发送缓冲 |
| `read_buffer_size` | `65_536` | 接收缓冲 |
| `max_outbound_queue` | `10_000` | 背压阈值 |

## 3. 加载方式

### 3.1 框架无关

```php
$config = require __DIR__ . '/config/messaging.php';
Messaging::configure($config);
```

### 3.2 Laravel

发布配置：

```bash
php artisan vendor:publish --tag=messaging-config
```

### 3.3 Symfony

```yaml
# config/packages/messaging.yaml
messaging:
    websocket:
        allowed_origins: ['https://app.example.com']
```

### 3.4 运行时覆盖

```php
// 命令式覆盖
Messaging::server('ws://0.0.0.0:8080', [
    'websocket' => [
        'allowed_origins' => ['https://app.example.com'],
        'heartbeat_interval' => 15,
    ],
]);
```

## 4. 环境变量

| 变量 | 说明 | 默认 |
|---|---|---|
| `MESSAGING_TRANSPORT` | 传输层 | `auto` |
| `MESSAGING_LOG_LEVEL` | 日志级别 | `info` |
| `MESSAGING_PUBSUB_DRIVER` | 总线驱动 | `memory` |
| `MESSAGING_REDIS_HOST` | Redis 主机 | `127.0.0.1` |
| `MESSAGING_REDIS_PORT` | Redis 端口 | `6379` |
| `MESSAGING_NODE_ID` | 节点 ID | 自动生成 |

## 5. 多环境配置

```php
$config = match (env('APP_ENV')) {
    'production' => require __DIR__ . '/messaging.prod.php',
    'staging'    => require __DIR__ . '/messaging.staging.php',
    default      => require __DIR__ . '/messaging.dev.php',
};
```

## 6. 配置校验

启动时自动校验，错误立即抛出 `InvalidConfigException`。
可使用 `php bin/messaging self-check` 提前发现配置 / 环境问题。
