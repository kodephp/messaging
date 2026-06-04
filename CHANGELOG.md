# Changelog

本文件记录 `kode/messaging` 的所有重要变更。
格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

---

## [2.1.0] - 2026-06-04

### Added
- `bin/messaging` CLI 工具扩展到 12 个子命令：`version` / `protocols` / `self-check` / `config` / `doc` / `start` / `stop` / `status` / `reload` / `logs` / `worker` / `release`
- `bin/messaging start --daemon --name=X` 后台启动，写入 `var/run/<name>.pid` 与 `var/log/<name>.log`
- `bin/messaging release --bump=patch|minor|major|prerelease` 一键升级版本号 + 更新 CHANGELOG + 打 tag
- `bin/messaging release --bump=minor --commit --tag --push` 全自动发布：commit → tag → push
- 部署脚本：`docker/Dockerfile`（多阶段、非 root、setcap、HEALTHCHECK）、`docker-compose.yml`、`deploy/systemd/messaging@.service`、`deploy/systemd/messaging.service`、`deploy/supervisor/messaging.conf`、`deploy/nginx/messaging.conf`、`deploy/install.sh`
- 详细部署文档 `docs/deployment.md`：systemd / supervisor / Docker / Kubernetes / Nginx / CLI 自带进程管理
- 详细发布文档 `docs/release.md`：维护者发布流程、版本号规范、Packagist / Docker 同步
- 进程管理：CLI 自带 `status` / `stop` / `reload` / `logs`（`-f` 实时跟踪，`--all` 批量操作）
- `examples/README.md` — 所有 examples 总览与测试片段
- `docs/examples/README.md` — 业务场景示例
- `Client\Builder` 新增 `normalizedScheme()` 公开方法（支持 `mqtt://broker:1883` → `mqtt` 的归一化映射）

### Changed
- `Client\Builder::connect()` 现在**幂等**——重复调用返回同一连接实例，`open` 事件只触发一次
- `Client\Builder::subscribe()` / `publish()` / `send()` 在连接未建立/已关闭场景下，抛清晰的 `\RuntimeException`
- `Client\Builder::subscribe()` / `publish()` 在不支持该方法的适配器上抛 `\LogicException`
- `Client\Builder::connect()` 在适配器返回 null 时抛清晰的 `\RuntimeException`
- 适配器未注册时通过 `AdapterNotFoundException::forScheme()` 给出已注册协议列表
- `bin/messaging` 12 个子命令：增加彩色输出、posix/proc 跨平台兼容、Windows 降级
- `docs/quick-start.md` 加入 11 协议示例 + 12 子命令一览 + 与 kode/process 协作
- `docs/architecture.md` 加入 5 层模型 + 协议矩阵 + CLI 工具架构
- `docs/configuration.md` 加入 NATS / STOMP / gRPC / WebTransport / RTMP 5 个新协议的配置项
- `docs/index.md` 加入 `deployment.md` / `release.md` 链接
- 项目规则 `.trae/rules/project_rules.md §11` 扩展：仓库与分支约定（origin / master）、11.6 上传流程、11.7 hotfix、11.8 仓库清单、11.9 撤销发布、11.10 禁止事项

### Fixed
- `Client\Builder` 在未连接场景调用 `subscribe` / `publish` 时的 NPE 风险
- `php bin/messaging start --protocol=xxx` 不再仅 echo 占位，--daemon 真正后台启动

### Tests
- 新增 `tests/Unit/ClientBuilderTest.php`：11 个边界测试用例
- 新增 `tests/Unit/_fixtures/`：`InMemoryAdapter` / `FailingAdapter` / `NoSubscribeAdapter` / `NoPublishAdapter` / `FakeConnection`
- 测试总数：110 / 110 通过（断言 315 条）

---

## [2.0.0] - 2026-06-03

### Added
- **NATS** 适配器：pub/sub、request/reply、subject 通配符 (`*` / `>`)、嵌入式 Broker
- **STOMP** 适配器：1.0/1.1/1.2 帧格式、SUBSCRIBE/SEND/ACK、嵌入式 Broker
- **gRPC Streaming** 适配器：4 种 RPC 模型（Unary / Server Streaming / Client Streaming / Bidirectional）
- **WebTransport** 适配器：HTTP/2-fallback 接口、Bidi / Unidi / Datagram 抽象
- **RTMP** 适配器：handshake + chunk 解析 + AMF0 编码

### Changed
- `Client\Builder` 扩展协议级 publish / subscribe 委托方法
- `Messaging::normalizeScheme()` / `defaultPort()` 覆盖新增 5 种协议

---

## [1.1.0] - 2026-06-03

### Added
- **Long-Polling** 适配器（HTTP/1.1）：服务端 + 客户端 + Hub 推送中心
- **CoAP** 适配器（RFC 7252）：支持 CON/NON/ACK/RST + CoapBlock 分块传输
- GitHub Actions CI 配置 `.github/workflows/ci.yml`

---

## [1.0.0] - 2026-06-03

### Added
- 核心：WebSocket / SSE / MQTT / UDP 4 大协议
- 中间件管道：Auth / RateLimit / Codec
- Pub/Sub 总线：Memory / Redis / Channel
- PHP 8.2 / 8.3 / 8.4 / 8.5 兼容性
- Apache-2.0 许可证
- PSR-3 / PSR-4 / PSR-7 / PSR-14 / PSR-18 合规
