# 业务示例

这些示例是**完整可运行**的端到端业务场景，演示如何把 `kode/messaging` 接入到真实项目中。

> 与 `examples/` 下的"最小可运行片段"不同，这里的示例包含**业务流程、状态管理、外部依赖**。

## 目录

| 文件 | 场景 | 关键 API |
|---|---|---|
| [chat.php](chat.php) | WebSocket 聊天室 | `Messaging::pubsub('memory')`、广播 |
| [push.php](push.php) | SSE 实时推送（多频次） | `->interval()`、`WeakMap` 连接池 |
| [iot.php](iot.php) | MQTT IoT 网关 + 监控 | 多客户端、`withWill`、阈值告警 |
| [rpc.php](rpc.php) | WebSocket JSON-RPC | 请求-响应模式、错误处理 |

## 运行

每个文件顶部 PHPDoc 都注明了运行命令，典型流程：

```bash
# 启动依赖服务（如 Mosquitto）
docker run -d --name mosquitto -p 1883:1883 eclipse-mosquitto:2

# 启动业务
php docs/examples/chat.php

# 另一终端 / 浏览器测试
npx wscat -c ws://localhost:8080
```

## 1. chat.php — 聊天室

- 客户端发送纯文本消息 → 服务端通过内存 Pub/Sub 总线广播
- 加入/离开时推送系统消息
- 每 5 秒推送一次在线人数

**关键点**：

```php
$bus = Messaging::pubsub('memory');

->on('message.received', function ($conn, $message) use ($bus) {
    $bus->publish('chat:room', [...]);
})
```

集群场景把 `'memory'` 改为 `'redis'` 即可透明升级。

## 2. push.php — 实时推送

- 每 1 秒推送 tick（时间戳）
- 每 5 秒推送通知
- 业务侧可通过 Pub/Sub 总线触发即时推送

**关键点**：

- `->interval(1000)` 与 `->interval(5000)` 注册两个不同频率的回调
- `WeakMap` 跟踪活跃连接，避免内存泄漏

## 3. iot.php — IoT 网关

- 3 个房间的温度/湿度传感器（模拟数据）
- 独立监控客户端订阅 + 阈值告警
- LWT（Last Will Testament）让 Broker 知道 gateway 离线

**关键点**：

```php
->withWill('gateway/status', 'offline', 1, true)  // qos=1, retain=true
```

## 4. rpc.php — JSON-RPC

**协议**：

```json
// 请求
{"id": "req-1", "method": "user.get", "params": {"id": 42}}

// 响应
{"id": "req-1", "result": {"id": 42, "name": "User-42"}}

// 错误
{"id": "req-1", "error": {"code": -32601, "message": "Method not found"}}
```

**启动**：

```bash
# 终端 1：服务端
php docs/examples/rpc.php server

# 终端 2：客户端
php docs/examples/rpc.php client
```

**关键点**：

- 服务端方法以数组注册，分发时按 `method` 字段路由
- 异常被转换为 JSON-RPC 标准错误码

## 接下来

- 完整协议文档：[docs/websocket.md](../websocket.md) / [docs/sse.md](../sse.md) / [docs/mqtt.md](../mqtt.md) ...
- 简单协议示例：[examples/](../../examples/)
- 部署：[docs/deployment.md](../deployment.md)
