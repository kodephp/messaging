# RTMP

> 适用：直播源接入（OBS / FMLE / 摄像头推流）
> 方案：`rtmp://` / `rtmps://`
> 端口：默认 `1935`

RTMP（Real-Time Messaging Protocol）是 Adobe 定义的实时消息协议，常见于：
- 直播推流（OBS / FMLE / 手机直播 SDK）
- 视频会议
- 实时音视频

> ⚠️ 本适配器**仅实现握手 + 命令分发 + 基础 chunk 解析**，
> 不实现 FLV 封装、video/audio 解码、H.264/AAC 拆解。
> 如需大规模 CDN 分发，请用 `nginx-rtmp` / `srs` / `crtmpserver`。

## 协议结构

```
┌──────────────┐
│   Handshake  │  C0/C1/C2 ↔ S0/S1/S2
└──────┬───────┘
       ↓
┌──────────────┐
│   Chunk 流   │  Basic Header + Message Header + Extended Timestamp + Chunk Data
└──────┬───────┘
       ↓
┌──────────────┐
│   Messages   │  0x14: AMF0 Command（connect / createStream / publish / play）
│              │  0x12: AMF0 Data
│              │  0x08: Audio
│              │  0x09: Video
└──────────────┘
```

## 服务端

```php
use Kode\Messaging\Messaging;

Messaging::server('rtmp://0.0.0.0:1935')
    ->on('message.received', function ($conn, $message) {
        $event = $message->event(); // 'connect' / 'publish' / 'play' / audio/video
        $topic = $message->topic(); // app name
        $body  = $message->payload();
        echo "[rtmp] event={$event} topic={$topic} body={$body}\n";
    })
    ->start();
```

> 📌 收到 `publish` 后，客户端推流的所有 audio/video 帧也会以 `message.received` 事件投递。

## 客户端

当前版本**仅提供服务端实现**（嵌入式拉流/推流对接）。
客户端（推流）需通过 `ffmpeg` / `OBS` 等外部工具对接本服务。

## AMF0 命令

`connect` / `createStream` / `publish` / `play` 是 RTMP 的基础命令，负载用 AMF0 编码：

| 字段 | 说明 |
|---|---|
| Command Name | `'connect'` / `'createStream'` / `'publish'` / `'play'` |
| Transaction ID | 数字 |
| Command Object | 名/值对象（`app`、`flashVer` 等） |
| Optional | 附加参数（如 `play` 的 stream key） |

## 配置项

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `chunk_size` | int | `4096` | 出向 chunk 大小 |
| `window_ack_size` | int | `2500000` | Window ACK size |
| `peer_bandwidth` | int | `2500000` | 对端带宽 |
| `app` | string | `live` | 默认 app |

## 协议事件

| 事件 | 参数 |
|---|---|
| `connection.open` | `$connection` |
| `message.received` | `$connection`, `$message`（`event`=`connect`/`publish`/`play`/audio/video） |
| `error.protocol` | `$peer`, `$error` |

## 与 OBS 集成

1. 启动本服务
2. OBS → 设置 → 推流 → 自定义：
   - 服务：`rtmp://localhost:1935/live`
   - 串流密钥：`room-001`
3. 业务层订阅 `publish` 事件即可获得推流元数据

## 与 FFmpeg 集成

```bash
# 推流到本服务
ffmpeg -re -i input.mp4 -c copy -f flv rtmp://localhost:1935/live/key1

# 从本服务拉流
ffmpeg -i rtmp://localhost:1935/live/key1 -c copy output.flv
```

## 安全建议

- 生产使用 `rtmps://` + TLS
- 业务层校验 app / stream key
- 设置合理的 chunk_size 防止 OOM
- 使用 `kode/queue` 落地数据（音频/视频帧）

---

## 限流（三层防御，基于 `kode/limiting`）

> 推荐生产环境部署 Redis 存储的限流器，跨进程 / 跨机器共享状态。

### 1. 连接级限流（防握手洪水）

新 TCP 连接进入之前检查，限制单 IP 的最大并发连接数。**容量 = 允许的最大并发数**。

### 2. 命令级限流（防 AMF0 命令洪水）

每个 AMF0 command（`connect` / `createStream` / `publish` / `play`）进入时检查，
按 `connection_id` 限流。

### 3. 消息级限流（业务层自定义）

通过 `Builder::middleware()` 注册的中间件管道，
对所有 `message.received` 事件（包含 audio / video 帧）生效。

### 限流配置（`config/messaging.php`）

```php
'rate_limit' => [
    'enabled' => true,
    'rtmp' => [
        // 连接级：单 IP 最多 100 并发连接
        'connection' => [
            'driver'   => 'token_bucket',
            'capacity' => 100,
            'rate'     => 1.0,                 // 每秒补充 1 个令牌（连接数/秒）
            'store'    => 'redis',             // memory | redis | memcached | pdo
            'store_opts' => [
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'password' => null,
                'database' => 0,
            ],
            'ttl'      => 60,
            'prefix'   => 'rtmp:conn:',
        ],
        // 命令级：单连接令牌桶 200 容量，每秒 50 个 AMF0 command
        'command' => [
            'driver'   => 'token_bucket',
            'capacity' => 200,
            'rate'     => 50.0,
            'store'    => 'redis',
            'store_opts' => [
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'password' => null,
                'database' => 0,
            ],
            'ttl'      => 60,
            'prefix'   => 'rtmp:cmd:',
        ],
    ],
],
```

### 用法 A：基于配置自动注入

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\Adapter\Rtmp\Server;
use Kode\Messaging\Middleware\RateLimit\RateLimitFactory;

$config = require __DIR__ . '/config/messaging.php';

$builder = Messaging::server('rtmp://0.0.0.0:1935')
    ->on('connection.open', fn ($conn) => /* ... */)
    ->on('message.received', fn ($conn, $msg) => /* ... */)
    ->on('rate_limit.exceeded', function (array $payload) {
        // 限流埋点 / 告警
        error_log("[rtmp] rate limited: {$payload['type']} peer={$payload['peer']}");
    });

// 注入限流器到 RTMP 适配器（连接级 + 命令级）
$adapter = $builder->adapter(); /** @var Server $adapter */
if ($config['rate_limit']['enabled'] ?? true) {
    $connLimiter = RateLimitFactory::create($config['rate_limit']['rtmp']['connection']);
    $cmdLimiter  = RateLimitFactory::create($config['rate_limit']['rtmp']['command']);
    $adapter->setRateLimiters($connLimiter, $cmdLimiter);
}

// 注入消息级限流中间件（业务层）
$builder->middleware(
    RateLimitFactory::middleware($config['rate_limit']['rtmp']['command'], 'rtmp:msg:')
);

$builder->start();
```

### 用法 B：直接构造限流器

```php
use Kode\Messaging\Adapter\Rtmp\Server;
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;
use Kode\Messaging\Middleware\RateLimit\SlidingWindowMiddleware;

// 内存版令牌桶
$cmdLimiter = TokenBucketMiddleware::memory(
    capacity: 200,
    refillRate: 50.0,
    keyPrefix: 'rtmp:cmd:',
);

// 分布式 Redis 版滑动窗口
$apiLimiter = SlidingWindowMiddleware::distributed(
    redisHost: '127.0.0.1',
    redisPort: 6379,
    capacity: 1000,
    windowSize: 1.0,                  // 1 秒内最多 1000 个
    password: null,
    database: 0,
);

// 注入 RTMP
$adapter->setRateLimiters(connectionLimiter: $connLimiter, commandLimiter: $cmdLimiter);
```

### 限流事件

| 事件 | 参数 | 触发条件 |
|---|---|---|
| `rate_limit.exceeded` | `type`（connection/command）, `peer`, `limiter`, `info`（wait_time、remaining 等） | 任一层限流被触发 |
| `error.protocol` | `peer`, `error` | 限流 + 协议错误统一事件 |

### 限流响应

| 类型 | 行为 |
|---|---|
| 连接级 | 拒绝接受（关闭 socket） |
| 命令级 | 关闭当前连接，emit `error.protocol` |
| 消息级（中间件） | 抛 `MessagingException(429)`，由 `Builder::on('error.protocol')` 接收 |

### 与 `kode/limiting` 的协作

- 单进程：使用 `MemoryStore`，零依赖
- 分布式：使用 `RedisStore`（Lua 脚本保证原子性） / `MemcachedStore` / `PdoStore`
- 推荐生产：Redis Cluster / Sentinel 模式，自动故障转移
- 详见 [kode/limiting 文档](https://packagist.org/packages/kode/limiting)
