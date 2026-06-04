# 示例代码

`kode/messaging` 提供的**可直接运行**示例，按协议分组。所有示例均以 `php xxx.php` 启动，使用默认端口（`Messaging::defaultPort()`）。

## 1. 一览表

| 文件 | 角色 | 默认端口 / 端点 | 关键依赖 |
|---|---|---|---|
| [websocket_server.php](websocket_server.php) | WebSocket 服务端 | 8080 | — |
| [websocket_client.php](websocket_client.php) | WebSocket 客户端 | — | echo.websocket.org |
| [sse_server.php](sse_server.php) | SSE 服务端（每秒 tick） | 8081 | — |
| [mqtt_publish.php](mqtt_publish.php) | MQTT 发布 5 条消息 | 1883 | MQTT broker |
| [mqtt_subscribe.php](mqtt_subscribe.php) | MQTT 订阅 `demo/#` | 1883 | MQTT broker |
| [udp_server.php](udp_server.php)（待补） | UDP 接收 | 8082 | — |
| [longpolling_server.php](longpolling_server.php) | Long-Polling 服务端 | 8083 | — |
| [longpolling_client.php](longpolling_client.php) | Long-Polling 客户端 | — | — |
| [coap_server.php](coap_server.php) | CoAP 服务端（IoT 资源） | 5683 | — |
| [coap_client.php](coap_client.php) | CoAP 客户端（GET/PUT） | — | — |
| [nats_server.php](nats_server.php) | NATS 嵌入式 Broker | 4222 | — |
| [nats_client.php](nats_client.php) | NATS 客户端（Pub/Sub） | — | — |
| [stomp_server.php](stomp_server.php) | STOMP 嵌入式 Broker | 61613 | — |
| [grpc_server.php](grpc_server.php) | gRPC Streaming | 50051 | — |
| [webtransport_server.php](webtransport_server.php) | WebTransport（HTTP/3-fallback） | 4433 | — |
| [rtmp_server.php](rtmp_server.php) | RTMP 直播接收 | 1935 | — |

> 提示：通过 `php bin/messaging start --protocol=ws --port=8080` 可以**不写代码**启动任一协议的内置服务（见 [docs/quick-start.md §2](../quick-start.md)）。

## 2. 通用启动方式

```bash
# 1. 安装依赖
composer install

# 2. 启动任一示例
php examples/websocket_server.php
php examples/sse_server.php
php examples/mqtt_publish.php

# 3. 通过 CLI 工具（不写代码）
php bin/messaging start --protocol=ws --port=8080
```

## 3. 各协议测试片段

### 3.1 WebSocket

浏览器控制台：

```javascript
const ws = new WebSocket('ws://localhost:8080');
ws.onmessage = e => console.log('[recv]', e.data);
ws.onopen = () => ws.send('hello');
```

或用 `wscat`：

```bash
npx wscat -c ws://localhost:8080
> hello
< echo: hello
```

### 3.2 SSE

```bash
curl -N http://localhost:8081
```

或浏览器 `EventSource`：

```javascript
const es = new EventSource('http://localhost:8081');
es.addEventListener('tick', e => console.log(JSON.parse(e.data)));
```

### 3.3 MQTT

启动一个本地 broker（推荐 docker）：

```bash
docker run -d --name mosquitto -p 1883:1883 eclipse-mosquitto:2
```

订阅：

```bash
MQTT_HOST=127.0.0.1 php examples/mqtt_subscribe.php
```

另一个终端发布：

```bash
MQTT_HOST=127.0.0.1 php examples/mqtt_publish.php
```

### 3.4 UDP

```bash
php examples/udp_server.php
# 另一终端
echo "ping" | nc -u 127.0.0.1 8082
```

### 3.5 Long-Polling

```bash
php examples/longpolling_server.php
# 测试探活
curl http://127.0.0.1:8083/ping
# 触发响应：写入 /tmp/lp-push-orders 文件
echo '{"data":"hi"}' | sudo tee /tmp/lp-push-orders
```

### 3.6 CoAP

使用 `libcoap` 工具：

```bash
# Debian/Ubuntu
sudo apt install libcoap3-bin

# 启动
php examples/coap_server.php

# 测试
coap-client -m get coap://127.0.0.1:5683/sensors/temp
coap-client -m put -e "23.5" coap://127.0.0.1:5683/sensors/temp
```

### 3.7 NATS

```bash
# 启动嵌入式 broker
php examples/nats_server.php &
# 启动客户端（同一文件既是发布者也是订阅者）
php examples/nats_client.php
```

### 3.8 STOMP

```bash
php examples/stomp_server.php &
# 用 STOMP 客户端连：推荐 stomp-cli / python stomp.py
```

### 3.9 gRPC

```bash
php examples/grpc_server.php
# 用 grpcurl 测试
grpcurl -plaintext -d '{"name":"kode"}' \
    -import-path / -proto helloworld.proto \
    127.0.0.1:50051 helloworld.Greeter/SayHello
```

### 3.10 RTMP

```bash
php examples/rtmp_server.php
# OBS / FFmpeg 推流
ffmpeg -re -i input.mp4 -c copy -f flv rtmp://127.0.0.1:1935/live/stream-key
```

## 4. examples 目录约定

- 文件名小写下划线
- 顶部 PHPDoc 注明运行命令、依赖、测试方法
- 默认端口与 `Messaging::defaultPort()` 对齐
- 不写业务状态管理（仅为演示）
- 不依赖任何外部框架（Laravel / Symfony 等）

## 5. 后续

- 详细协议文档：[docs/websocket.md](../websocket.md) / [docs/sse.md](../sse.md) / [docs/mqtt.md](../mqtt.md) / ...
- 进阶示例：[docs/examples/](../docs/examples/) 中的 chat.php / push.php / iot.php / rpc.php
