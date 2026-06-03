# Changelog

本文件记录 `kode/messaging` 的所有重要变更。
格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

---

## [2.0.0] - 2026-06-03

### Added
- **NATS** 适配器：pub/sub、request/reply、subject 通配符 (`*` / `>`)、嵌入式 Broker
- **STOMP** 适配器：1.0/1.1/1.2 帧格式、SUBSCRIBE/SEND/ACK、嵌入式 Broker
- **gRPC Streaming** 适配器：4 种 RPC 模型（Unary / Server Streaming / Client Streaming / Bidirectional），基于 5 字节 length-prefixed frame
- **WebTransport** 适配器：HTTP/2-fallback 接口、Bidi / Unidi / Datagram 抽象；为 aioquic / msquic 等 HTTP/3 后端预留挂钩
- **RTMP** 适配器：handshake + chunk 解析 + AMF0 编码（connect / createStream / publish / play）
- 协议规范化扩展：`nats` / `stomp` / `grpc` / `webtransport` / `rtmp` / `wt` / `stomps` / `grpc-web` / `rtmps`
- 默认端口扩展：`nats:4222` / `stomp:61613` / `grpc:50051` / `webtransport:4433` / `rtmp:1935`
- `Client\Builder::subscribe(topic, cb)` / `publish(topic, payload)` 协议级发布订阅
- 单元测试：`NatsCodecTest` / `NatsConnectionTest` / `StompCodecTest` / `GrpcCodecTest` / `WebTransportCodecTest` / `RtmpAmf0Test`

### Changed
- `Client\Builder` 扩展协议级 publish / subscribe 委托方法
- `Messaging::normalizeScheme()` / `defaultPort()` 覆盖新增 5 种协议
- `src/register.php` 自动注册全部 18 个协议适配器（Client + Server）

### Notes
- **2.0.0** 是协议大版本（6 → 11 协议），但保持 API 向后兼容
- HTTP/3 + QUIC 真实 WebTransport 需后端 aioquic / msquic，本版本提供业务接口
- gRPC Streaming 当前为 gRPC-Web 风格（HTTP/1.1 + chunked），完整 HTTP/2 计划 2.1

---

## [1.1.0] - 2026-06-03

### Added
- **Long-Polling** 适配器（HTTP/1.1）：服务端 + 客户端 + Hub 推送中心
- **CoAP** 适配器（RFC 7252）：支持 CON/NON/ACK/RST + CoapBlock 分块传输
- GitHub Actions CI 配置 `.github/workflows/ci.yml`
- 单元测试 `CoapBlockTest.php` / `LongPollingHubTest.php`

### Changed
- `Messaging::normalizeScheme()` 支持 `poll://` / `coap://`
- `config/messaging.php` 加入 `long-polling` 与 `coap` 配置段

---

## [1.0.0] - 2026-06-03

### Added
- 核心：WebSocket / SSE / MQTT / UDP 4 大协议
- 中间件管道：Auth / RateLimit / Codec
- Pub/Sub 总线：Memory / Redis / Channel
- PHP 8.2 / 8.3 / 8.4 / 8.5 兼容性（含 pipe operator 运行时检测）
- Apache-2.0 许可证
- PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18 合规
- 完整的协议无关 `MessageInterface` / `ConnectionInterface` 抽象
