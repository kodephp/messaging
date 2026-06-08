# Changelog

本文件记录 `kode/messaging` 的所有版本变更。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

> ⚠ 发布前**三处必须一致**：
> 1. `src/Support/Version.php` 的 `MAJOR` / `MINOR` / `PATCH` 常量
> 2. `composer.json` 的 `version` 字段
> 3. 本文件顶部段落
> 4. git tag `vX.Y.Z`
>
> 推荐使用 `php bin/messaging release --bump=patch|minor|major --commit --tag --push` 自动维护。

---

## [2.2.1] - 2026-06-08

### 🐛 修复
- **README 协议矩阵**：RTMP 客户端列从 `规划中` 改为 `✅`，与 `src/Adapter/Rtmp/`（2.0.0 已实现）保持一致
- **README 文档清单**：补全 `docs/` 下 11 协议对应的全部 9 个协议指南链接（之前漏了 `mqtt.md` / `nats.md` / `stomp.md` / `grpc.md` / `webtransport.md` / `rtmp.md` / `coap.md` / `udp.md` / `long-polling.md`）
- **README 示例清单**：补全 `examples/` 下 11 协议对应的可运行示例（UDP / CoAP / NATS / STOMP / gRPC / Long-Polling / WebTransport / RTMP）
- **CHANGELOG.md**：v2.2.0 发布时缺失 CHANGELOG，本补丁补齐 v1.0.0 → v2.2.0 的完整变更记录（含 RTMP 完整实现与三层限流细节）

### 📚 文档
- 完善 README 协议矩阵、文档清单、示例清单的完整度
- 新增 `CHANGELOG.md`（之前 release 流程中提到但实际未生成）

---

## [2.2.0] - 2026-06-08

### ✨ 新增（Features）
- **RTMP 协议加固** —— `kode/messaging` 完整 11 协议中的最后一环。`src/Adapter/Rtmp/` 提供：
  - 完整握手（C0/C1/C2 ↔ S0/S1/S2）
  - 基础 Header + 消息 Header + Extended Timestamp 的 chunk 解析
  - AMF0 命令分发（`connect` / `createStream` / `publish` / `play`）
  - audio（0x08）/ video（0x09）帧透传
- **RTMP 三层限流**（基于 `kode/limiting`，可插拔 Redis/Memory/Memcached/PDO 存储）
  - 连接级：单 IP 最大并发连接数（防握手洪水）
  - 命令级：单连接 AMF0 command 频率（防命令洪水）
  - 消息级：业务层 `Builder::middleware()` 注入（防业务滥用）
- `rate_limit.exceeded` 事件，统一所有限流埋点
- `RateLimitFactory::create()` / `RateLimitFactory::middleware()` 工厂方法
- `TokenBucketMiddleware::memory()` / `distributed()` 快捷构造
- `SlidingWindowMiddleware` 分布式滑动窗口（Lua 原子）

### 🧪 测试
- `tests/Unit/RtmpServerRateLimitTest.php` —— 覆盖连接级、命令级、`onLimited` 回调、IPv4/IPv6、限流键隔离
- `tests/Unit/RtmpAmf0Test.php` —— AMF0 编解码单测
- `tests/Unit/RateLimitTest.php` —— 限流中间件单测

### 📚 文档
- `docs/rtmp.md` —— 协议结构、AMF0 命令、配置项、协议事件、OBS/FFmpeg 集成、限流三层防御
- `examples/rtmp_server.php` + `examples/rtmp_server.README.md` —— 可直接运行的 RTMP 服务端示例（含限流）

### 🔧 修复
- `fix(cli): release command should not duplicate existing CHANGELOG sections`

---

## [2.1.0] - 2026-05-20

### ✨ 新增
- gRPC 完整 HTTP/2 + HPACK + TLS 实现
- WebTransport 原生 HTTP/3 支持（可挂接 aioquic / msquic）
- `kode/ai-agent` suggest 协作
- `ext-msgpack` 建议依赖

### 📚 文档
- 完善 `docs/grpc.md` HTTP/2 帧解析章节
- 完善 `docs/webtransport.md` HTTP/3-fallback 章节

---

## [2.0.0] - 2026-05-05

### ✨ 新增（**11 协议完整**）
- **NATS**（2.0.0 新增） —— 客户端 + 嵌入式 Broker，pub/sub、request/reply
- **STOMP 1.2**（2.0.0 新增） —— 客户端 + 嵌入式 Broker，兼容 RabbitMQ / ActiveMQ
- **gRPC Streaming**（2.0.0 新增） —— 客户端 + 服务端，4 种流式调用
- **WebTransport**（2.0.0 新增） —— HTTP/3-fallback，双工流
- **RTMP**（2.0.0 新增） —— 直播源接入，handshake + chunk + AMF0 命令分发
- **CoAP**（1.1.0 → 2.0.0 升级） —— IoT 传感器、NB-IoT
- **Long-Polling**（1.1.0 → 2.0.0 升级） —— WebSocket 回退

### 🔧 破坏性变更
- 最低 PHP 版本 **8.1 → 8.2**
- 移除 `kode/messaging` 1.x 中过时的 `Connection` 抽象（迁移到 `ConnectionInterface`）
- `Builder` API 重构（统一 11 协议入口）

---

## [1.1.0] - 2026-04-12

### ✨ 新增
- **Long-Polling** —— HTTP/1.1 长轮询，WebSocket 回退
- **CoAP (RFC 7252)** —— UDP 上的受限应用协议，IoT 传感器

---

## [1.0.0] - 2026-03-01

### 🎉 首发
- **WebSocket**（RFC 6455）服务端 + 客户端
- **SSE**（HTML5）服务端 + 客户端
- **MQTT** 3.1.1 / 5.0 客户端
- **UDP / Datagram**（RFC 768）服务端 + 客户端
- 统一门面 `Kode\Messaging\Messaging`
- 中间件管道（Auth / RateLimit / Codec）
- 路由（Prefix / Regex Matcher）
- Pub/Sub 总线（Memory / Channel / Redis）
- PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18 合规
- Apache-2.0 许可证

---

## 版本号总览

| 版本 | 发布日期 | 关键特性 |
|---|---|---|
| 2.2.1 | 2026-06-08 | 修复 README RTMP 状态、补全文档清单、补全 CHANGELOG |
| 2.2.0 | 2026-06-08 | RTMP 三层限流 + 防护 |
| 2.1.0 | 2026-05-20 | gRPC HTTP/2 + WebTransport HTTP/3 |
| 2.0.0 | 2026-05-05 | **11 协议完整**（NATS / STOMP / gRPC / WebTransport / RTMP） |
| 1.1.0 | 2026-04-12 | Long-Polling + CoAP |
| 1.0.0 | 2026-03-01 | WebSocket + SSE + MQTT + UDP（首发） |

[Unreleased]: https://github.com/kodephp/messaging/compare/v2.2.1...HEAD
[2.2.1]: https://github.com/kodephp/messaging/releases/tag/v2.2.1
[2.2.0]: https://github.com/kodephp/messaging/releases/tag/v2.2.0
[2.1.0]: https://github.com/kodephp/messaging/releases/tag/v2.1.0
[2.0.0]: https://github.com/kodephp/messaging/releases/tag/v2.0.0
[1.1.0]: https://github.com/kodephp/messaging/releases/tag/v1.1.0
[1.0.0]: https://github.com/kodephp/messaging/releases/tag/v1.0.0
