# 快速开始

5 分钟跑通 `kode/messaging` 的第一个 WebSocket / SSE / MQTT 服务。

## 1. 安装

```bash
composer require kode/messaging
```

可选依赖（按需）：

```bash
composer require kode/fibers      # 协程支持
composer require kode/event       # 事件派发
composer require kode/http-client # 长轮询回退
```

## 2. WebSocket 服务端

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

## 3. 浏览器测试

新建 `index.html`：

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

浏览器打开即可看到双向通信。

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

## 6. 与 kode/process 协作（多 Worker）

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

## 7. 下一步

- 架构：[docs/architecture.md](./architecture.md)
- 协议细节：[docs/websocket.md](./websocket.md) / [docs/sse.md](./sse.md) / [docs/mqtt.md](./mqtt.md)
- 中间件：[docs/middleware.md](./middleware.md)
- 发布订阅：[docs/pubsub.md](./pubsub.md)
- 示例集合：[docs/examples/](./examples/)
