# kode/messaging 文档

`kode/messaging` 是 `kode/*` 家族中的统一消息层 Composer 包，封装 **WebSocket**、**SSE**、**MQTT**、**UDP**、**Long-Polling**、**CoAP** 等长连接 / 实时消息协议，提供**一致的 API**、**协议无关的消息抽象**和**可插拔的扩展点**。

## 目录

### 入门
- [快速开始](./quick-start.md) — 5 分钟跑通第一个 WebSocket
- [架构设计](./architecture.md) — 协议适配器、消息管道、连接抽象
- [配置说明](./configuration.md) — 全部配置项

### 协议指南
- [WebSocket](./websocket.md) — 浏览器长连接、双向通信
- [SSE](./sse.md) — 服务端推送、轻量流
- [MQTT](./mqtt.md) — IoT 设备、Pub/Sub、低带宽
- [UDP](./udp.md) — 实时音视频、组播广播、低延迟
- [Long-Polling](./long-polling.md) — WebSocket 回退、HTTP 长轮询
- [CoAP](./coap.md) — IoT 传感器、NB-IoT、低功耗设备

### 进阶
- [发布订阅](./pubsub.md) — 跨协议统一事件总线
- [中间件](./middleware.md) — 鉴权、限流、编解码
- [协议扩展路线图](./roadmap.md) — 计划支持的协议（NATS / STOMP / gRPC / QUIC / WebTransport）
- [迁移指南](./migration.md) — 从 workerman / Swoole 迁移

### 示例
- [聊天](./examples/chat.php) — WebSocket 聊天室
- [推送](./examples/push.php) — SSE 实时通知
- [IoT](./examples/iot.php) — MQTT 设备接入
- [RPC](./examples/rpc.php) — WebSocket RPC

## 一图概览

```
┌────────────────────────────────────────────────────────┐
│                  Kode\Messaging 门面                    │
│   Messaging::server()  Messaging::client()             │
│   Messaging::pubsub()  Messaging::version()            │
└──────────────────────┬─────────────────────────────────┘
                       │
        ┌──────┬───────┼───────┬──────┐
        │      │       │       │      │
   ┌────▼──┐┌──▼───┐┌─▼────┐┌─▼────┐┌─▼────┐┌─▼────┐
   │WebSock││ SSE  ││ MQTT ││ UDP  ││ Long-││ CoAP │
   │  et   ││      ││      ││      ││Poll  ││(UDP) │
   └──┬────┘└──┬───┘└──┬───┘└──┬───┘└──┬───┘└──┬───┘
      │       │       │       │       │       │
      └───────┴───────┴───────┴───────┴───────┘
                       │
            ┌──────────▼──────────┐
            │  Connection / Codec │
            │  Middleware Pipeline│
            └──────────┬──────────┘
                       │
            ┌──────────▼──────────┐
            │  Transport (TCP/UDP)│
            │  PHP stream / Swoole│
            │  / Swow / Workerman │
            └─────────────────────┘
```

## 设计原则

1. **统一 API** — 一个 `Messaging::server()` 启动所有协议
2. **协议无关** — 业务代码面向 `MessageInterface` 编程
3. **可插拔** — 新协议通过 `Adapter\*` 扩展
4. **可选增强** — 核心包零强制依赖
5. **PSR 合规** — PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18
6. **协程友好** — 与 `kode/fibers`、`kode/runtime` 协作

## PHP 版本

- **最低**：PHP 8.2
- **推荐**：PHP 8.3 / 8.4
- **已验证**：PHP 8.5（开发分支）
- 新特性（enum、readonly、property hooks、pipe operator）在运行时做兼容降级

## 许可证

Apache-2.0
