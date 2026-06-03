# Changelog

所有版本的变更都记录在此文件。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## [Unreleased]

## [1.1.0] - 2026-06-03

### Added

- **Long-Polling** 适配器（HTTP/1.1）：服务端 + 客户端，作为 WebSocket 回退
  - `LongPolling\Server` / `LongPolling\LongPollingConnection`
  - `LongPolling\Client` / `LongPolling\LongPollingClientConnection`
  - `LongPolling\Hub` 主题订阅 / 推送中心（支持 memory / channel / redis 三种 driver）
  - `LongPollingException`（6001-6005）
  - 支持 GET/POST/PUT/DELETE/OPTIONS、CORS、hold 超时、分块传输、Topic 定向推送
- **CoAP** 适配器（RFC 7252）：服务端 + 客户端，IoT / NB-IoT
  - `Coap\CoapPacket`（RFC 7252 §3 编解码）
  - `Coap\CoapCode` / `Coap\CoapType` / `Coap\CoapOption` / `Coap\CoapBlock`（RFC 7959）
  - `Coap\CoapConnection` / `Coap\Server` / `Coap\Client`
  - `CoapException`（7001-7006）
  - 支持 CON/NON/ACK/RST、CON 重传、token 匹配、Observe 基础、Block1/Block2
- 协议扩展路线图 `docs/roadmap.md`
- 新增协议指南 `docs/long-polling.md` / `docs/coap.md`
- 新增可运行示例 `examples/longpolling_{server,client}.php` / `examples/coap_{server,client}.php`
- 单元测试 `tests/Unit/CoapCodecTest.php` / `CoapBlockTest.php` / `LongPollingTest.php` / `LongPollingHubTest.php` / `CoapExceptionTest.php` / `Feature/LongPollingPushTest.php`
- GitHub Actions CI 配置 `.github/workflows/ci.yml`（PHP 8.2 / 8.3 / 8.4 / 8.5 矩阵）

### Fixed

- `src/Version.php` 移至 `src/Support/Version.php`，符合 PSR-4 自动加载
- `src/Messaging.php` 引用修正为 `Kode\Messaging\Support\Version`
- `LongPolling\Server` 增加 `Hub` 集成与 Topic 索引
- 修正 `CoapBlock` 编码对齐 RFC 7959 实际格式
- 修正 `Frame::decode()` 必须传 `mustMask=false` 用于服务端帧

### Changed

- `Messaging::normalizeScheme()` 支持 `poll://` / `long-polling://` / `lp://` / `coap://` / `coaps://`
- `Messaging::defaultPort()` 加入 long-polling 8083 / coap 5683/5684
- `config/messaging.php` 加入 `long-polling` 与 `coap` 默认配置段
- `composer.json` 增加 `files` 自动加载（`src/register.php`），引用 vendor/autoload.php 即可注册全部 6 个协议
- `docs/index.md` 协议列表、架构图更新
- `SKILL.md` / `project_rules.md` 同步更新
- 包版本号从 1.0.0 升到 1.1.0

## [1.0.0] - 2026-06-XX

### Added

- 初始发布 `kode/messaging` 1.0.0
- **WebSocket** 适配器（RFC 6455）：服务端 + 客户端，握手鉴权、Origin 校验、ping/pong 心跳、二进制帧、压缩扩展
- **SSE** 适配器（HTML5）：服务端、客户端、PSR-7 Emitter、自动重试
- **MQTT** 适配器：客户端（QoS 0/1/2、LWT、TLS、MQTT 3.1.1/5.0），实验性 Broker
- **UDP / Datagram** 适配器：服务端、客户端、单播/广播/组播、Datagram 抽象
- 协议无关的 `MessageInterface` / `ConnectionInterface`
- 中间件管道（鉴权 / 限流 / 编解码 / 校验 / 追踪）
- 发布订阅总线（`memory` / `channel` / `redis`）
- 路由（基于 event / topic）
- 集群模式（与 `kode/process` 协作）
- 静态门面 `Kode\Messaging\Messaging`
- PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18 合规
- PHP 8.2 / 8.3 / 8.4 / 8.5 兼容（含 pipe operator 8.5 降级）
- 协程友好（与 `kode/fibers` 协作）
- 命令行工具 `bin/messaging`（`start` / `stop` / `reload` / `status` / `info`）
- 完整中文文档与示例
