# 快速开始

5 分钟跑通 `kode/messaging` 的第一个服务。本指南覆盖 **WebSocket / SSE / MQTT / UDP / Long-Polling / CoAP / NATS / STOMP / gRPC / WebTransport / RTMP** 共 11 种协议。

## 0. 环境要求

| 项 | 最低 | 推荐 |
|---|---|---|
| PHP | 8.2 | 8.3 / 8.4 |
| 扩展 | 无强制 | `ext-sockets`、`ext-openssl`、`ext-pcntl` |
| 协程（可选） | `kode/fibers` | `kode/fibers` |
| Composer | 2.x | 2.x |

执行 `php bin/messaging self-check` 即可一键自检。

---

## 1. 安装

```bash
composer require kode/messaging
```

可选依赖（按需安装）：

```bash
composer require kode/fibers      # 协程支持
composer require kode/event       # PSR-14 事件派发
composer require kode/http-client # 长轮询回退
composer require kode/log         # PSR-3 日志
composer require kode/jwt         # JWT 鉴权
composer require kode/process     # 多 Worker / 集群
```

复制默认配置：

```bash
cp vendor/kode/messaging/config/messaging.php config/messaging.php
```

---

## 2. CLI 工具一览

`vendor/bin/messaging`（或 `php bin/messaging`）内置 12 个子命令：

```bash
# 查看版本与运行时
php bin/messaging version

# 列出全部已注册协议
php bin/messaging protocols

# 环境自检（PHP 版本、扩展、autoload、协议注册）
php bin/messaging self-check

# 打印默认配置
php bin/messaging config
php bin/messaging config websocket
php bin/messaging config mqtt

# 打开文档
php bin/messaging doc quick-start
php bin/messaging doc nats

# 启动内置协议服务（无需写代码）
php bin/messaging start --protocol=ws     --port=8080                  # 前台
php bin/messaging start --protocol=ws     --daemon --name=ws-prod      # 后台
php bin/messaging start --protocol=sse    --port=8081
php bin/messaging start --protocol=mqtt   --port=1883
php bin/messaging start --protocol=nats   --port=4222

# 启动自定义服务
php bin/messaging start examples/websocket_server.php
php bin/messaging start examples/websocket_server.php --daemon --name=chat

# 进程管理（CLI 自带）
php bin/messaging status                                      # 所有后台服务
php bin/messaging status --name=ws-prod                       # 指定服务
php bin/messaging logs   --name=ws-prod -f                    # 实时跟踪
php bin/messaging reload --name=ws-prod                       # SIGHUP
php bin/messaging stop   --name=ws-prod                       # SIGTERM
php bin/messaging stop   --name=ws-prod --force               # SIGKILL
php bin/messaging stop   --all

# 多 Worker（需要 kode/process）
php bin/messaging worker examples/websocket_server.php --count=4

# 维护者：发布新版本
php bin/messaging release --bump=minor --commit --tag --push
```

---

## 3. WebSocket 服务端

新建 `ws_server.php`：

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open', function ($conn) {
        echo "[open] {$conn->id()}\n";
        $conn->send(['event' => 'welcome', 'id' => $conn->id()]);
    })
    ->on('message.received', function ($conn, $message) {
        echo "[msg] {$message->payload()}\n";
        $conn->send(['echo' => $message->payload()]);
    })
    ->on('connection.close', function ($conn) {
        echo "[close] {$conn->id()}\n";
    })
    ->start();
```

启动：

```bash
php ws_server.php
```

浏览器测试 `index.html`：

```html
<!DOCTYPE html>
<html>
<body>
<pre id="log"></pre>
<script>
const ws = new WebSocket('ws://localhost:8080');
const log = document.getElementById('log');
const append = (s) => log.textContent += s + '\n';

ws.onopen    = () => append('open');
ws.onmessage = (e) => append('recv: ' + e.data);
ws.onclose   = () => append('close');

setInterval(() => ws.send('hello ' + Date.now()), 1000);
</script>
</body>
</html>
```

---

## 4. SSE 一行启动

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('sse://0.0.0.0:8081')
    ->on('connection.open', function ($conn) {
        $conn->send(['event' => 'tick', 'data' => ['time' => time()]]);
    })
    ->interval(1000) // 每 1 秒推送
    ->start();
```

浏览器端：

```javascript
const es = new EventSource('http://localhost:8081');
es.addEventListener('tick', (e) => console.log(JSON.parse(e.data)));
```

---

## 5. MQTT 客户端

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Messaging\Messaging;

// 订阅
$sub = Messaging::client('mqtt://broker.example.com:1883')
    ->withClientId('php-subscriber')
    ->subscribe('sensors/+/temperature', function ($topic, $payload, $message) {
        echo "[$topic] $payload (qos={$message->qos()})\n";
    })
    ->connect();

// 发布
$pub = Messaging::client('mqtt://broker.example.com:1883')
    ->withClientId('php-publisher')
    ->connect();

$pub->publish('sensors/room-1/temperature', '23.5', ['qos' => 1]);

// 保持运行
$sub->loop();
```

---

## 6. UDP / CoAP / IoT

### 6.1 UDP 服务端（实时音视频、游戏、广播）

```php
use Kode\Messaging\Messaging;

Messaging::server('udp://0.0.0.0:8082')
    ->on('message.received', function ($conn, $msg) {
        echo "[udp] from {$conn->remoteAddress()}: {$msg->payload()}\n";
        $conn->send('ack');
    })
    ->start();
```

### 6.2 CoAP 客户端（IoT）

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('coap://devices.example.com:5683');
$resp = $client->request('GET', '/sensors/temp', [
    'observe' => true,           // RFC 7641 订阅推送
    'accept'  => 50,             // application/json
]);
echo $resp->payload();
```

---

## 7. NATS / STOMP / gRPC

### 7.1 NATS Pub/Sub

```php
use Kode\Messaging\Messaging;

$bus = Messaging::pubsub('nats://demo.nats.io:4222');
$bus->subscribe('orders.created', function ($msg) {
    echo "[nats] {$msg->payload()}\n";
});
$bus->publish('orders.created', ['order_id' => 1001, 'amount' => 99.0]);
$bus->run();
```

### 7.2 STOMP 队列

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('stomp://broker:61613')
    ->withCredentials('admin', 'admin');

$client->connect()
       ->subscribe('/queue/jobs', function ($frame) {
           echo "[stomp] {$frame->body()}\n";
           $frame->ack();
       })
       ->run();
```

### 7.3 gRPC Unary / Streaming

```php
use Kode\Messaging\Messaging;

// 服务端
Messaging::server('grpc://0.0.0.0:50051')
    ->method('/helloworld.Greeter/SayHello', function ($request) {
        return ['message' => 'Hello, ' . $request->name];
    })
    ->start();

// 客户端
$reply = Messaging::client('grpc://localhost:50051')
    ->connect()
    ->call('/helloworld.Greeter/SayHello', ['name' => 'kode']);
```

---

## 8. WebTransport / RTMP

### 8.1 WebTransport（HTTP/3-fallback）

```php
Messaging::server('wt://0.0.0.0:4433')
    ->on('connection.open', fn($c) => $c->send('welcome via wt'))
    ->start();
```

### 8.2 RTMP 接收直播源

```php
Messaging::server('rtmp://0.0.0.0:1935')
    ->on('connection.open', function ($conn) {
        echo "[rtmp] publisher: {$conn->remoteAddress()}\n";
    })
    ->start();
```

OBS / FFmpeg 推流地址：

```
rtmp://your-host:1935/live/stream-key
```

---

## 9. 与 kode/process 协作（多 Worker）

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Messaging\Messaging;
use Kode\Process\Kode as Process;

Process::worker(Messaging::server('ws://0.0.0.0:8080'))
    ->count(4)              // 4 个 Worker 进程
    ->withCluster()         // 启用分布式广播
    ->start();
```

通过 CLI 一行启动：

```bash
php bin/messaging worker examples/websocket_server.php --count=4
```

---

## 10. 中间件与鉴权

```php
use Kode\Messaging\Messaging;
use Kode\Messaging\Middleware\Auth\BearerAuthMiddleware;
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;
use Kode\Messaging\Middleware\Codec\JsonCodec;

Messaging::server('ws://0.0.0.0:8080')
    ->middleware(new BearerAuthMiddleware('your-secret'))
    ->middleware(new TokenBucketMiddleware(100, 10))   // 容量 100，10/s
    ->middleware(new JsonCodec())
    ->on('message.received', fn($c, $m) => $c->send($m->payload()))
    ->start();
```

---

## 11. 发布订阅总线（跨协议）

```php
use Kode\Messaging\Messaging;

$bus = Messaging::pubsub('memory');  // memory | channel | redis

// WebSocket 收到消息 → 广播到 SSE
$ws = Messaging::server('ws://0.0.0.0:8080');
$ws->on('message.received', function ($c, $m) use ($bus) {
    $bus->publish('chat', $m->payload());
});

$sse = Messaging::server('sse://0.0.0.0:8081');
$sse->on('connection.open', function ($c) use ($bus) {
    $bus->subscribe('chat', fn($msg) => $c->send($msg));
});

$ws->start();
$sse->start();
```

---

## 12. 部署

### 12.1 systemd

`/etc/systemd/system/messaging.service`：

```ini
[Unit]
Description=kode/messaging
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php /srv/app/bin/messaging start /srv/app/examples/websocket_server.php
Restart=always
RestartSec=3
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now messaging
sudo systemctl status messaging
```

### 12.2 Docker

```bash
docker build -t kode/messaging -f docker/Dockerfile .
docker run -d --name messaging -p 8080:8080 kode/messaging
```

详见 [docs/deployment.md](./deployment.md)。

---

## 13. 下一步

| 主题 | 文档 |
|---|---|
| 架构 | [docs/architecture.md](./architecture.md) |
| 协议细节 | [docs/websocket.md](./websocket.md) / [docs/sse.md](./sse.md) / [docs/mqtt.md](./docs/mqtt.md) / [docs/udp.md](./udp.md) / [docs/coap.md](./coap.md) / [docs/nats.md](./nats.md) / [docs/stomp.md](./stomp.md) / [docs/grpc.md](./grpc.md) / [docs/webtransport.md](./webtransport.md) / [docs/rtmp.md](./rtmp.md) |
| 中间件 | [docs/middleware.md](./middleware.md) |
| Pub/Sub | [docs/pubsub.md](./pubsub.md) |
| 配置 | [docs/configuration.md](./configuration.md) |
| 部署 | [docs/deployment.md](./deployment.md) |
| 迁移 | [docs/migration.md](./migration.md) |
| 路线图 | [docs/roadmap.md](./roadmap.md) |
| 示例集合 | [docs/examples/](./examples/) |
