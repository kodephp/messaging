# 配置

## 1. 配置文件

`config/messaging.php`：

```php
return [
    // 默认协议
    'default' => 'ws',

    // 通用
    'logger' => null,                 // Psr\Log\LoggerInterface
    'event_dispatcher' => null,       // Psr\EventDispatcher\EventDispatcherInterface

    // 传输层选择（auto / stream / sockets / swoole / swow）
    'transport' => 'auto',

    // WebSocket
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8080,
        'max_frame_size' => 1_048_576,        // 1 MiB
        'max_connections' => 10000,
        'allowed_origins' => ['*'],           // 生产必须明确指定
        'heartbeat_interval' => 30,           // 秒
        'heartbeat_timeout' => 60,            // 秒
        'handshake_timeout' => 10,            // 秒
        'subprotocols' => [],                 // 支持的子协议
    ],

    // SSE
    'sse' => [
        'host' => '0.0.0.0',
        'port' => 8081,
        'retry_ms' => 3000,
        'keepalive_seconds' => 15,
        'max_connections' => 10000,
        'heartbeat_event' => 'ping',          // 心跳事件名
    ],

    // MQTT
    'mqtt' => [
        'host' => '127.0.0.1',
        'port' => 1883,
        'version' => '3.1.1',                 // '3.1.1' | '5.0'
        'keepalive' => 60,
        'clean_session' => true,
        'max_inflight' => 1000,
        'max_packet_size' => 268_435_456,     // 256 MiB
        'session' => [
            'driver' => 'memory',            // memory | redis | apcu
            'config' => [],
        ],
        'tls' => [
            'cafile' => null,
            'local_cert' => null,
            'local_pk' => null,
            'verify_peer' => true,
        ],
    ],

    // 发布订阅
    'pubsub' => [
        'default' => 'memory',               // memory | channel | redis
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'db' => 0,
            'prefix' => 'messaging:',
        ],
        'channel' => [
            'driver' => 'kode-process',      // 依赖 kode/process
        ],
    ],

    // 集群
    'cluster' => [
        'enabled' => false,
        'driver' => 'redis',
        'node_id' => null,                   // 自动生成
        'heartbeat' => 5,                    // 秒
    ],

    // 性能调优
    'tuning' => [
        'worker_count' => 1,                 // 单进程模式
        'use_fibers' => true,                // 协程
        'send_buffer_size' => 65536,         // 字节
        'read_buffer_size' => 65536,
    ],
];
```

## 2. 运行时配置

```php
// 命令式覆盖
Messaging::server('ws://0.0.0.0:8080', [
    'websocket' => [
        'allowed_origins' => ['https://app.example.com'],
        'heartbeat_interval' => 15,
    ],
]);
```

## 3. 加载配置

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

`config/messaging.php` 自动加载。

### 3.3 Symfony

```yaml
# config/packages/messaging.yaml
messaging:
    websocket:
        allowed_origins: ['https://app.example.com']
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
