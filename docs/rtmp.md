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
