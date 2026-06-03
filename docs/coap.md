# CoAP 协议指南

CoAP（Constrained Application Protocol，RFC 7252）是**为 IoT 设备设计的 RESTful 协议**，基于 UDP，支持 GET/POST/PUT/DELETE 等 HTTP 方法，并提供可靠（CON/ACK）与不可靠（NON）两种传输模式。

本包提供完整的 CoAP 数据包编解码、服务端、客户端实现，**纯 PHP、零扩展依赖**。

## 适用场景

| 场景 | 推荐 |
|---|---|
| NB-IoT 传感器 | ✅ CoAP |
| LoRaWAN 网关 | ✅ CoAP |
| 智能家居（Zigbee/IP） | ✅ CoAP |
| 工业 PLC 监控 | ✅ CoAP |
| 高吞吐双向通信 | ❌ 用 WebSocket |
| 大消息（> 1 KiB） | ❌ 用 HTTP/2 或 WebSocket |

## URL Scheme

| Scheme | 协议 |
|---|---|
| `coap://` | UDP 明文（默认端口 5683） |
| `coaps://` | DTLS 加密（默认端口 5684，实验性） |

## 服务端

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\Adapter\Coap\CoapCode;
use Kode\Messaging\Adapter\Coap\CoapOption;

Messaging::server('coap://0.0.0.0:5683')
    ->on('message.received', function ($conn, $message) {
        $ctx = $message->headers();   // 包含 coap.method / coap.path / coap.mid / coap.type ...
        $method = $ctx['coap.method'];  // 'GET' | 'POST' | 'PUT' | 'DELETE'
        $path   = $ctx['coap.path'];    // '/sensors/temp'
        $mid    = $ctx['coap.mid'];     // 16-bit message id
        $token  = $ctx['coap.token'];   // binary token（可选）

        // 业务处理
        if ($path === '/sensors/temp' && $method === 'GET') {
            $conn->sendRequest(CoapCode::CONTENT, $path, json_encode([
                'value' => 23.5,
                'unit'  => 'C',
            ]), [
                'mid'   => $mid,           // 复用客户端 MID
                'token' => $token,         // 复用 token
                'type'  => CoapType::ACK,  // 对 CON 的回应
                'content_format' => CoapOption::FMT_JSON,
            ]);
        } elseif ($path === '/sensors/temp' && $method === 'PUT') {
            // 接收并存储传感器数据
            file_put_contents('/tmp/sensor-temp', $message->payload());
            $conn->sendRequest(CoapCode::CHANGED, $path, '', [
                'mid' => $mid, 'token' => $token, 'type' => CoapType::ACK,
            ]);
        } else {
            $conn->sendRequest(CoapCode::NOT_FOUND, $path, 'Not Found', [
                'mid' => $mid, 'token' => $token, 'type' => CoapType::ACK,
            ]);
        }
    })
    ->on('coap.timeout', function ($info) {
        // 多次重传后仍未收到 ACK
        // 业务可记录、上报
    })
    ->on('coap.retransmit', function ($info) {
        // 重传提示，业务可执行实际重传
    })
    ->start();
```

### 行为说明

- `CoapType::CON` 触发可靠传输（CON → ACK + 重传）
- `CoapType::NON` 是单向不可靠消息（适合心跳）
- `Message ID` 16 位，由连接缓存
- `Token` 用于匹配请求与响应（最长 8 字节）
- `Observe`（RFC 7641）观察模式：客户端注册资源，服务端推送变更
- 资源路径以 `/` 分割，按 `Uri-Path` option 拼接

## 客户端

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\Adapter\Coap\CoapCode;
use Kode\Messaging\Adapter\Coap\CoapOption;
use Kode\Messaging\Adapter\Coap\CoapType;

$conn = Messaging::client('coap://devices.local:5683')->connect();

// 1) GET 资源
$mid = $conn->sendRequest(CoapCode::GET, '/sensors/temp', '', [
    'accept' => CoapOption::FMT_JSON,
    'token'  => "\x01\x02",
    'type'   => CoapType::CON,  // 可靠
]);
// 等待响应（业务可注册 ack 回调）
// ...

// 2) POST 数据
$conn->sendRequest(CoapCode::POST, '/control/led', '{"state":"on"}', [
    'content_format' => CoapOption::FMT_JSON,
    'type'           => CoapType::CON,
]);

// 3) NON 模式（不可靠，适合心跳）
$conn->sendRequest(CoapCode::POST, '/heartbeat', 'ping', [
    'type' => CoapType::NON,
]);
```

## 协议消息结构

```
 0                   1                   2                   3
 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|Ver| T |  TKL  |      Code     |          Message ID           |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|   Token (if any, TKL bytes) ...
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|   Options (if any) ...
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|1 1 1 1 1 1 1 1|    Payload (if any) ...
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
```

- `Ver`：版本号（必须为 1）
- `T`：消息类型（CON/NON/ACK/RST）
- `TKL`：Token 长度（0-8 字节）
- `Code`：响应码（class.detail，class 0-5）
- `Message ID`：16-bit
- `Options`：增量编码（delta + length）
- `Payload`：以 `0xFF` 标记开头

## CoAP 响应码

| 类别 | 码 | 含义 |
|---|---|---|
| 0.xx | 0.01-0.04 | 请求方法（GET/POST/PUT/DELETE） |
| 2.xx | 2.01-2.05 | Success（Created/Deleted/Valid/Changed/Content） |
| 4.xx | 4.00-4.15 | Client Error |
| 5.xx | 5.00-5.04 | Server Error |

完整列表见 `CoapCode` 常量。

## CoAP 选项

| 编号 | 名称 | 用途 |
|---|---|---|
| 1 | If-Match | 条件请求（ETag） |
| 3 | Uri-Host | 主机名 |
| 5 | If-None-Match | 条件请求 |
| 7 | Uri-Port | 端口 |
| 8 | Location-Path | 资源位置 |
| 11 | Uri-Path | 资源路径（多段） |
| 12 | Content-Format | 载荷格式（JSON/XML/...） |
| 14 | Max-Age | 缓存时间 |
| 15 | Uri-Query | 查询参数（key=val） |
| 17 | Accept | 可接受格式 |

## Observe 模式（RFC 7641）

服务端可注册"观察者"，资源变更时主动推送：

```php
// 服务端伪代码
$observeStore->register($path, $connection);
// 资源变更时
foreach ($observeStore->subscribers($path) as $conn) {
    $conn->sendRequest(CoapCode::CONTENT, $path, $newValue, [
        'type' => CoapType::CON,  // 或 NON
        'extra' => [CoapOption::OBSERVE => chr($seq++)],
    ]);
}
```

> ⚠️ 本包当前不内置 Observe 注册中心；可通过 `kode/pubsub` 或自实现。

## 配置项

```php
'coap' => [
    'host'                  => '0.0.0.0',
    'port'                  => 5683,
    'max_packet_size'       => 1_152,       // RFC 7252 建议 MTU
    'ack_timeout_ms'        => 2_000,       // CON 超时
    'max_retransmit'        => 4,
    'retransmit_backoff'    => 2.0,
    'enable_observe'        => true,
    'default_response_format' => 50,        // application/json
],
```

## 与 MQTT 的取舍

| 维度 | CoAP | MQTT |
|---|---|---|
| 传输 | UDP | TCP |
| 模式 | 请求-响应 | 发布-订阅 |
| 头部开销 | 4 字节 | 2 字节+ |
| 头部开销 | 4 字节+options | 2 字节+ |
| 主题 | 路径 | 主题 |
| 适用 | RESTful、稀疏数据 | 高频、群发 |
| QoS | CON/NON | 0/1/2 |
| 加密 | DTLS | TLS |

**经验法则**：
- 数据点稀少、HTTP-like → CoAP
- 大量设备、高频上报 → MQTT
- 实时音视频 → UDP（本包独立实现）

## 故障排查

| 现象 | 排查 |
|---|---|
| 收不到响应 | CON 模式下检查 `ack_timeout_ms` 与 `max_retransmit` |
| 504 Gateway Timeout | 业务未在 hold 窗口内响应 |
| 4.04 Not Found | `Uri-Path` 路由未匹配 |
| 4.15 Unsupported Content-Format | 服务端不支持 `Accept` 指定的格式 |
| 解析失败 | MTU 超过 `max_packet_size`，启用 RFC 7959 block-wise |
