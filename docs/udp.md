# UDP / Datagram 协议指南

> 实现：UDP（RFC 768）
> 适用：实时音视频、实时游戏、低延迟 Pub/Sub、IoT 传感器、DNS 响应

## 1. 简介

UDP 是无连接传输层协议。`kode/messaging` 把每个 `datagram` 视作一条消息；连接是**逻辑连接**（对端地址 ip:port），不是物理 socket。

> 与 TCP 系列协议（WebSocket/SSE/MQTT）相比，UDP：
> - 无连接、无握手
> - 无顺序保证
> - 适合**实时+容忍丢包**场景
> - 单包最大 65 507 字节

## 2. 服务端

```php
use Kode\Messaging\Messaging;

Messaging::server('udp://0.0.0.0:8082')
    ->on('message.received', function ($conn, $message) {
        $peer = $conn->peer();        // "192.168.1.100:54321"
        $data = $message->payload();
        // 处理数据
        $conn->send("ack: $data");
    })
    ->start();
```

每个对端地址在内部被维护为一个 `UdpConnection`；该连接在第一次收到 datagram 时触发 `connection.open`，N 秒无活动后可视为超时。

## 3. 客户端

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('udp://192.168.1.1:8082')
    ->on('message.received', fn($c, $m) => print_r($m->payload()))
    ->connect();

$client->send('hello');
$client->send(['sensor' => 'temp', 'value' => 23.5]);
$client->loop();
```

## 4. 广播

```php
$conn = Messaging::client('udp://255.255.255.255:8082')
    ->withBroadcast()
    ->connect();

$conn->send('announce: hello world');
```

需要 `ext-sockets` 来设置 `SO_BROADCAST`；否则广播将失败。

## 5. 组播

```php
$conn = Messaging::client('udp://239.0.0.1:5000')
    ->withMulticast('239.0.0.1', '0.0.0.0')
    ->connect();
```

## 6. Datagram 抽象

`UdpConnection` 提供：

```php
$conn->peer();             // 当前对端地址
$conn->setPeer($ip, $port); // 切换对端
$conn->send($payload);     // 发送（同一 socket）
```

## 7. 协议分层上的应用

UDP 之上可承载任意应用层协议：

| 应用 | 描述 |
|---|---|
| **TFTP** | 简单文件传输（69/udp） |
| **DNS** | 域名解析（53/udp） |
| **NTP** | 时间同步（123/udp） |
| **RTP/RTCP** | 实时音视频（动态端口） |
| **QUIC** | HTTP/3、下一代传输（443/udp） |
| **CoAP** | IoT 受限设备（5683/udp） |
| **STUN/TURN** | NAT 穿透 |
| **WireGuard** | VPN |
| **mDNS** | 局域网服务发现 |
| **Syslog** | 日志（514/udp） |

## 8. 性能与限制

| 项 | 限制 |
|---|---|
| 单包最大 | 65 507 字节（含 IP 头 20B + UDP 头 8B） |
| 单进程吞吐 | 5–20K pps（纯 PHP）/ 200K+ pps（Swoole） |
| 顺序保证 | ❌ |
| 可靠传输 | ❌（业务层自己保证或选 TCP/QUIC） |
| 拥塞控制 | ❌ |

## 9. 完整示例：实时传感器采集

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\PubSub\MemoryBus;

$bus = Messaging::pubsub('memory');

// 边缘网关：收集所有传感器 UDP 报文
Messaging::server('udp://0.0.0.0:8082')
    ->on('message.received', function ($conn, $message) use ($bus) {
        $data = json_decode($message->payload(), true);
        if (!is_array($data)) {
            return;
        }
        // 业务总线分发
        $bus->publish("sensor.{$data['id']}", $data);
    })
    ->start();

// 同进程内 / 跨进程订阅
$bus->subscribe('sensor.*', function ($data) {
    // 写入数据库
});
```

完整可运行示例：[docs/examples/iot-udp.php](./examples/iot-udp.php)
