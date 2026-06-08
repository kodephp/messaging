# RTMP 服务端示例（含限流）

> 完整路径：`examples/rtmp_server.php`
> 协议：[docs/rtmp.md](../rtmp.md)
> 依赖：`kode/limiting` ^1.7

## 1. 用途

把 OBS / FMLE / ffmpeg 推送的 RTMP 直播流接入 `kode/messaging`，
业务层可以再分发到 WebSocket / SSE / UDP 等其他协议。

**不适用**：作为 CDN 大规模 RTMP 分发（请用 nginx-rtmp / srs）。

## 2. 启动

```bash
php examples/rtmp_server.php
```

默认监听：`0.0.0.0:1935`（RTMP 标准端口）。

可用环境变量覆盖（默认从 `config/messaging.php` 读取）：

| 变量 | 用途 | 默认 |
|---|---|---|
| `RTMP_HOST` | 监听地址 | `0.0.0.0` |
| `RTMP_PORT` | 监听端口 | `1935` |
| `RTMP_RATE_LIMIT_DISABLED` | 关闭限流（仅调试） | `0` |

## 3. 推流测试

### 3.1 用 FFmpeg 推流

```bash
# 推送本地视频文件
ffmpeg -re -i input.mp4 -c copy -f flv rtmp://127.0.0.1:1935/live/stream-key

# 推送摄像头（如 macOS facetime 摄像头）
ffmpeg -f avfoundation -i "0:0" -c:v libx264 -f flv rtmp://127.0.0.1:1935/live/cam

# 推送屏幕
ffmpeg -f avfoundation -i "1" -c:v libx264 -f flv rtmp://127.0.0.1:1935/live/screen
```

### 3.2 用 OBS 推流

1. OBS → 设置 → 推流
2. 服务类型：自定义
3. URL：`rtmp://127.0.0.1:1935/live`
4. 推流码：自定义（例：`stream-key`）

## 4. 三层限流（基于 `kode/limiting`）

本示例默认启用了**三层限流**防御，按 `config/messaging.php` 中的 `rate_limit.rtmp` 配置：

| 层级 | 算法 | 限什么 | Key | 默认容量 | 默认速率 |
|---|---|---|---|---|---|
| 连接级 | TokenBucket | TCP 握手频率 | `rtmp:conn:<ip>` | 100 | 1 / 秒 |
| 命令级 | TokenBucket | AMF0 command 频率 | `rtmp:cmd:<conn_id>` | 200 | 50 / 秒 |
| 消息级 | TokenBucket | `message.received` 频率 | `rtmp:msg:<conn_id>` | 1000 | 200 / 秒 |

### 4.1 触发限流时观察

启动后，开启推流 + 故意频繁重连（`for i in {1..200}; do ffmpeg ... & done`），即可在终端看到：

```
[rtmp] ⚠ rate limited: type=connection peer=127.0.0.1:54321 wait=0.99s
[rtmp] ⚠ rate limited: type=command peer=127.0.0.1:54322 wait=0.5s
```

### 4.2 切换存储后端

默认 `store=memory`（单进程）。生产环境推荐 Redis 跨机器共享：

```php
// config/messaging.php
'rate_limit' => [
    'rtmp' => [
        'connection' => [
            'driver'   => 'token_bucket',
            'capacity' => 100,
            'rate'     => 1.0,
            'store'    => 'redis',           // ← 改这里
            'store_opts' => [
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'password' => 'your-redis-pwd',
                'database' => 0,
            ],
        ],
    ],
],
```

### 4.3 切换算法

```php
'rate_limit' => [
    'rtmp' => [
        'command' => [
            'driver'   => 'sliding_window',  // 精确 QPS 控制
            'capacity' => 100,               // 100 / 秒
            'window'   => 1.0,
            'store'    => 'memory',
        ],
    ],
],
```

## 5. 业务集成示例

把 RTMP 收到的 video/audio 帧转推到 WebSocket 客户端（直播场景）：

```php
use Kode\Messaging\Messaging;

$rtmpBuilder = Messaging::server('rtmp://0.0.0.0:1935');
$rtmpBuilder->on('message.received', function ($conn, $msg) use ($wsPub) {
    $ctx = $msg->context();
    if (($ctx['rtmp_type'] ?? null) === 0x09) { // video
        // 把 video 帧转推到 WebSocket 广播
        $wsPub->broadcast($msg->payload());
    }
});

$wsBuilder = Messaging::server('ws://0.0.0.0:8080');
$wsBuilder->on('connection.open', function ($conn) {
    $conn->send(json_encode(['event' => 'live.start']));
});
```

> 提示：以上是简化版。生产环境一般用 Redis / Kafka / NATS 作为中间消息总线，
> 避免直接耦合 RTMP ↔ WebSocket。参见 [docs/architecture.md §4](../architecture.md)。

## 6. 故障排查

| 现象 | 排查方向 |
|---|---|
| OBS 推流失败，提示"无法连接服务器" | 检查 `php examples/rtmp_server.php` 进程是否在运行；检查 `netstat -lnp \| grep 1935` |
| 推流一会儿就断 | 检查 `error.protocol` 日志，看是否触发 `命令级限流`；可临时调大 `capacity` / `rate` |
| 高并发下端口耗尽 | 调整系统 `ulimit -n`；或使用 `kode/process` 多 Worker 部署 |
| Redis 连接失败 | 检查 `php -m \| grep redis` 是否安装 ext-redis（kode/limiting 的 redis 存储需要） |

## 7. 安全提醒

- 生产环境请配置 **Origin / Referer 校验**（参考 `docs/rtmp.md §安全`）
- 推荐把 RTMP 服务放在内网，前面挂 nginx-rtmp / SRS 作为反代与转协议
- 限流只是**第一道防线**，业务层还需结合鉴权、签名、防盗链等手段
