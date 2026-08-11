# kode/messaging 文档

`kode/messaging` 是 `kode/*` 家族中的统一消息层 Composer 包，封装 **11 种长连接 / 实时消息协议**（WebSocket、SSE、MQTT、UDP、Long-Polling、CoAP、NATS、STOMP、gRPC Streaming、WebTransport、RTMP），提供**一致的 API**、**协议无关的消息抽象**和**可插拔的扩展点**。

## 目录

### 入门
- [快速开始](./quick-start.md) — 5 分钟跑通第一个服务（含 CLI 工具一览）
- [架构设计](./architecture.md) — 协议矩阵、适配器、消息管道、连接抽象
- [配置说明](./configuration.md) — 全部 11 协议配置项 + 环境变量
- [部署指南](./deployment.md) — systemd / supervisor / Docker / K8s / Nginx 一键部署
- [发布指南](./release.md) — 维护者发布流程：版本号、commit、tag、Packagist、Docker Hub

### 协议指南（11 协议）
- [WebSocket](./websocket.md) — 浏览器长连接、双向通信
- [SSE](./sse.md) — 服务端推送、轻量流
- [MQTT](./mqtt.md) — IoT 设备、Pub/Sub、低带宽
- [UDP](./udp.md) — 实时音视频、组播广播、低延迟
- [Long-Polling](./long-polling.md) — WebSocket 回退、HTTP 长轮询
- [CoAP](./coap.md) — IoT 传感器、NB-IoT、低功耗设备
- [NATS](./nats.md) — 微服务 Pub/Sub、Service Mesh、request/reply
- [STOMP](./stomp.md) — 跨语言消息队列（RabbitMQ / ActiveMQ）
- [gRPC Streaming](./grpc.md) — 微服务 RPC、4 种流式调用
- [WebTransport](./webtransport.md) — HTTP/3 双工、低延迟浏览器消息
- [RTMP](./rtmp.md) — 直播推流（OBS / FMLE）

### 进阶
- [发布订阅](./pubsub.md) — 跨协议统一事件总线
- [中间件](./middleware.md) — 鉴权、限流、编解码
- [协议扩展路线图](./roadmap.md) — 计划与已实现的协议
- [迁移指南](./migration.md) — 从 workerman / Swoole 迁移

### CLI 工具（`bin/messaging`）
- `messaging version` — 版本 + 运行时信息
- `messaging protocols` — 已注册协议适配器
- `messaging self-check` — 环境自检
- `messaging config [section]` — 打印默认配置
- `messaging doc [name]` — 打开本地文档
- `messaging start <file|--protocol=...>` — 启动服务
- `messaging worker <file> --count=N` — 多 Worker（依赖 kode/process）

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
   ┌────┬────┬────┬────┬────┬────┬────┬────┬────┬────┬────┐
   │WS  │SSE │MQTT│UDP │Poll│CoAP│NATS│STMP│gRPC│WT  │RTMP│
   └──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬─┴──┬┘
      └────┴────┴────┴────┴────┴────┴────┴────┴────┴────┘
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

1. **统一 API** — 一个 `Messaging::server()` 启动 11 种协议
2. **协议无关** — 业务代码面向 `MessageInterface` 编程
3. **可插拔** — 新协议通过 `Adapter\*` 扩展
4. **可选增强** — 核心包零强制依赖
5. **PSR 合规** — PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18
6. **协程友好** — 与 `kode/fibers`、`kode/runtime` 协作

## PHP 版本

- **最低**：PHP 8.3
- **推荐**：PHP 8.3 / 8.4
- **已验证**：PHP 8.5（开发分支）
- 新特性（enum、readonly、property hooks、pipe operator）在运行时做兼容降级

## 许可证

Apache-2.0
