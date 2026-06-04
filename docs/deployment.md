# 部署指南

> 本文档涵盖 `kode/messaging` 在 **生产环境** 的多种部署方式：systemd / supervisor / Docker / Kubernetes / 手工部署，并提供 Nginx 反代、SRE 监控、灰度发布等实践建议。

## 1. 部署前置

### 1.1 服务器要求

| 项 | 最低 | 推荐 |
|---|---|---|
| CPU | 1 核 | 4 核+ |
| 内存 | 512 MB | 1 GB+ / Worker |
| 文件句柄 | `ulimit -n 65535` | 同上 |
| PHP | 8.2 | 8.3 / 8.4 |
| Composer | 2.x | 2.x |
| 操作系统 | Linux（任意） | Ubuntu 22.04+ / Debian 12+ / Rocky 9+ |

### 1.2 操作系统调优

```bash
# /etc/sysctl.d/99-messaging.conf
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
net.ipv4.ip_local_port_range = 1024 65535
fs.file-max = 2097152
fs.nr_open = 2097152
```

```bash
sudo sysctl --system
```

```bash
# /etc/security/limits.d/99-messaging.conf
www-data  soft  nofile  65535
www-data  hard  nofile  65535
www-data  soft  nproc   65535
www-data  hard  nproc   65535
```

### 1.3 目录结构（建议）

```
/srv/messaging/
├── app/                # 业务代码
│   ├── config/messaging.php
│   ├── examples/...
│   └── start.php
├── vendor/             # composer 依赖
├── var/
│   ├── log/            # 运行日志
│   ├── run/            # pid / sock
│   └── data/           # 业务持久化
└── deploy/             # 部署脚本
```

## 2. 方式一：systemd（推荐）

### 2.1 多实例（推荐）

`deploy/systemd/messaging@.service` 模板化注册：

```bash
sudo cp deploy/systemd/messaging@.service /etc/systemd/system/
sudo systemctl daemon-reload

# 启动 4 个协议服务
sudo systemctl enable --now messaging@ws.service
sudo systemctl enable --now messaging@sse.service
sudo systemctl enable --now messaging@mqtt.service
sudo systemctl enable --now messaging@nats.service
```

实例名 `%i` 会传给 `ExecStart`：

```ini
ExecStart=/usr/bin/php /srv/messaging/bin/messaging start --protocol=%i --port=${PORT}
```

#### 端口映射约定

| 实例 | 端口 | 协议 |
|---|---|---|
| `messaging@ws`   | 8080 | WebSocket |
| `messaging@sse`  | 8081 | SSE |
| `messaging@mqtt` | 1883 | MQTT client |
| `messaging@nats` | 4222 | NATS client |
| `messaging@grpc` | 50051 | gRPC |
| `messaging@udp`  | 8082 | UDP |
| `messaging@rtmp` | 1935 | RTMP |

### 2.2 单实例

`deploy/systemd/messaging.service` 适合单一协议场景：

```bash
sudo cp deploy/systemd/messaging.service /etc/systemd/system/
sudo systemctl enable --now messaging.service
```

### 2.3 常用命令

```bash
# 启动 / 停止 / 重启 / 状态
sudo systemctl start  messaging@ws
sudo systemctl stop   messaging@ws
sudo systemctl restart messaging@ws
sudo systemctl status messaging@ws

# 查看日志（journalctl）
sudo journalctl -u messaging@ws -f
sudo journalctl -u messaging@ws --since "1 hour ago"

# 修改后重载
sudo systemctl daemon-reload
sudo systemctl restart messaging@ws

# 开机自启
sudo systemctl enable messaging@ws
```

### 2.4 优雅停机

systemd 单元已设置：

```ini
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=20
ExecStop=/bin/kill -TERM $MAINPID
```

服务收到 `SIGTERM` 后会停止 `accept()`、等待已建立的连接关闭、最后退出。

## 3. 方式二：supervisor

适合无 systemd 容器 / 老旧发行版 / 跨平台开发。

### 3.1 安装

```bash
sudo apt install -y supervisor    # Debian / Ubuntu
sudo yum install -y supervisor   # CentOS / RHEL
```

### 3.2 部署

```bash
sudo mkdir -p /var/log/messaging
sudo cp deploy/supervisor/messaging.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start messaging:*
```

### 3.3 常用命令

```bash
# 查看状态
sudo supervisorctl status messaging:*

# 启动 / 停止 / 重启
sudo supervisorctl start   messaging:messaging-ws
sudo supervisorctl stop    messaging:messaging-ws
sudo supervisorctl restart messaging:messaging-ws

# 重读配置
sudo supervisorctl reread
sudo supervisorctl update
```

### 3.4 配置说明

| 项 | 作用 |
|---|---|
| `command` | 启动命令，可换 `--protocol=xxx` 或自定义文件 |
| `autostart` | 开机自启 |
| `autorestart` | 异常退出自动重启 |
| `startretries` | 最大重试次数 |
| `stopsignal` | 停止信号（必须是 TERM） |
| `stopwaitsecs` | 等待优雅退出秒数 |
| `stdout_logfile` | stdout 日志路径 |

## 4. 方式三：Docker

### 4.1 构建镜像

```bash
docker build -t kode/messaging:latest -f docker/Dockerfile .
```

镜像特性：

- 多阶段构建（builder + runtime），runtime 镜像约 80 MB
- 预装扩展：sockets / bcmath / pcntl / posix / opcache
- 非 root 运行（`kode` uid 1000）
- 启用 `setcap` 允许绑定 < 1024 端口（如 WebTransport 4433）
- 自带 HEALTHCHECK

### 4.2 docker run

```bash
docker run -d \
    --name messaging-ws \
    -p 8080:8080 \
    -p 8081:8081 \
    -v $PWD/config/messaging.php:/app/config/messaging.php:ro \
    -v $PWD/var/log:/app/var/log \
    --restart=unless-stopped \
    kode/messaging:latest \
    start --protocol=ws --port=8080
```

### 4.3 docker-compose

```bash
docker compose up -d
docker compose ps
docker compose logs -f messaging-ws
```

`docker-compose.yml` 内置 WebSocket / SSE / MQTT / NATS 客户端 + Redis 背板，开箱即用。

### 4.4 健康检查

```bash
docker inspect --format='{{.State.Health.Status}}' messaging-ws
```

返回 `healthy` 即代表 WebSocket 端口可接受连接。

## 5. 方式四：Kubernetes

### 5.1 Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: messaging-ws
  labels: {app: messaging, protocol: ws}
spec:
  replicas: 3
  selector:
    matchLabels: {app: messaging, protocol: ws}
  template:
    metadata:
      labels: {app: messaging, protocol: ws}
    spec:
      containers:
        - name: ws
          image: kode/messaging:latest
          args: ["start", "--protocol=ws", "--port=8080"]
          ports:
            - containerPort: 8080
              name: ws
          env:
            - name: MESSAGING_TRANSPORT
              value: "auto"
          readinessProbe:
            tcpSocket: {port: 8080}
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket: {port: 8080}
            initialDelaySeconds: 30
            periodSeconds: 30
          resources:
            requests: {cpu: 200m, memory: 256Mi}
            limits:   {cpu: 1,    memory: 512Mi}
          volumeMounts:
            - name: config
              mountPath: /app/config/messaging.php
              subPath: messaging.php
      volumes:
        - name: config
          configMap:
            name: messaging-config
```

### 5.2 Service

```yaml
apiVersion: v1
kind: Service
metadata:
  name: messaging-ws
spec:
  type: ClusterIP
  selector: {app: messaging, protocol: ws}
  ports:
    - port: 80
      targetPort: 8080
      name: ws
  sessionAffinity: ClientIP   # WebSocket 关键：保持客户端到同一 Pod
  sessionAffinityConfig:
    clientIP: {timeoutSeconds: 10800}
```

### 5.3 Ingress（Nginx Ingress Controller）

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: messaging-ws
  annotations:
    nginx.ingress.kubernetes.io/proxy-read-timeout: "3600"
    nginx.ingress.kubernetes.io/proxy-send-timeout: "60"
spec:
  rules:
    - host: ws.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: messaging-ws
                port: {number: 80}
```

## 6. Nginx 反向代理

> 当 messaging 部署在内网、对外只暴露 80/443 时使用。

完整模板见 [`deploy/nginx/messaging.conf`](../deploy/nginx/messaging.conf)，包含：

| 端点 | 关键配置 |
|---|---|
| `wss://ws.example.com` | `Upgrade` / `Connection: upgrade` / 长 timeout / 禁用缓冲 |
| `https://sse.example.com` | `proxy_buffering off` / `proxy_cache off` / 24h timeout |
| `https://lp.example.com`  | timeout 与 `hold_timeout_ms` 对齐 |

示例（WebSocket 段）：

```nginx
location / {
    proxy_pass http://messaging_ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout  3600s;
    proxy_buffering off;
}
```

## 7. 一键部署脚本

`deploy/install.sh` 整合以上所有步骤：

```bash
sudo ./deploy/install.sh                  # 默认 /srv/messaging + ws + 8080
sudo ./deploy/install.sh --target=/opt     # 自定义目录
sudo PROTOCOL=sse PORT=8081 ./deploy/install.sh
sudo ./deploy/install.sh --supervisor     # 强制使用 supervisor
sudo ./deploy/install.sh --nginx          # 同步 Nginx 反代模板
```

脚本会：

1. 同步代码 → 安装依赖 → 准备目录权限
2. 自动识别 systemd / supervisor
3. 注册并启动服务
4. 复制 Nginx 反代模板（可选）
5. 调用 `bin/messaging self-check` 验证环境

## 8. CLI 自带进程管理

`bin/messaging` 内置 daemon / status / stop / reload / logs 命令，适合**无 systemd/supervisor 的轻量部署**（单机 dev / 单实例测试）。

### 8.1 后台启动

```bash
# 内置协议服务
php bin/messaging start --protocol=ws --daemon --name=ws-prod --port=8080

# 自定义文件
php bin/messaging start examples/websocket_server.php --daemon --name=chat
```

输出：

```
✓ ws-prod 已后台启动 (pid=12345)
  日志: /srv/messaging/var/log/ws-prod.log
  管理: messaging stop --name=ws-prod | status | logs --name=ws-prod
```

### 8.2 状态

```bash
php bin/messaging status
# 或
php bin/messaging status --name=ws-prod
```

输出：

```
  ws-prod                         pid=12345   ✓ running
  chat                            pid=12346   ✗ stopped (stale)
```

### 8.3 停止 / 重启

```bash
php bin/messaging stop --name=ws-prod           # 优雅停机（SIGTERM）
php bin/messaging stop --name=ws-prod --force   # 强制停止（SIGKILL）
php bin/messaging stop --all                    # 停止所有
```

### 8.4 reload

```bash
php bin/messaging reload --name=ws-prod
# 发送 SIGHUP，让 kode/process 重新加载配置
```

### 8.5 日志

```bash
php bin/messaging logs --name=ws-prod           # 末尾 50 行
php bin/messaging logs --name=ws-prod -n=200    # 末尾 200 行
php bin/messaging logs --name=ws-prod -f        # 实时跟踪（tail -F）
```

### 8.6 文件位置

| 用途 | 路径 |
|---|---|
| PID 文件 | `var/run/<name>.pid` |
| 日志文件 | `var/log/<name>.log` |

### 8.7 适用场景

- ✅ 本地开发
- ✅ 轻量单机部署
- ✅ Docker 容器内（Dockerfile HEALTHCHECK 仍然适用）
- ❌ 生产多实例 / 跨主机 → 使用 systemd / supervisor / k8s
- ❌ Windows → 仅能前台运行，使用 `bin/messaging start ...` 不加 `--daemon`

## 9. 环境变量

| 变量 | 说明 | 默认 |
|---|---|---|
| `APP_ENV` | 环境标识 | `production` |
| `MESSAGING_TRANSPORT` | 传输层 | `auto` |
| `MESSAGING_LOG_LEVEL` | 日志级别 | `info` |
| `MESSAGING_PUBSUB_DRIVER` | 总线驱动 | `memory` |
| `MESSAGING_REDIS_HOST` / `PORT` | Redis | `127.0.0.1:6379` |
| `MESSAGING_NODE_ID` | 节点 ID | 自动 |
| `MQTT_BROKER_HOST` / `PORT` | MQTT 外部 broker | `127.0.0.1:1883` |
| `MESSAGING_HEALTH_HOST` / `PORT` | Docker 健康检查目标 | `127.0.0.1:8080` |

## 10. 监控与可观测性

### 10.1 内置事件

监听关键事件并对接 Prometheus / StatsD：

```php
use Kode\Messaging\Messaging;

$dispatcher = $container->get(Psr\EventDispatcher\EventDispatcherInterface::class);

Messaging::server('ws://0.0.0.0:8080')
    ->on('connection.open',  fn($c) => $metrics->inc('ws.connect'))
    ->on('connection.close', fn($c) => $metrics->inc('ws.disconnect'))
    ->on('message.received', fn($c, $m) => $metrics->inc('ws.message'))
    ->on('error.protocol',   fn(...$a) => $metrics->inc('ws.error'));
```

### 10.2 Prometheus 集成（示例）

```php
// 暴露 /metrics 端点
$registry = new \Prometheus\CollectorRegistry(new InMemory());
$counter = $registry->getOrRegisterCounter('messaging', 'messages_total', ['protocol']);

Messaging::server('ws://0.0.0.0:8080')
    ->on('message.received', function ($c, $m) use ($counter) {
        $counter->inc(['ws']);
    });
```

### 10.3 日志

```php
$logger = new \Monolog\Logger('messaging', [new StreamHandler('/var/log/messaging/app.log')]);
Messaging::configure(['logger' => $logger]);
```

### 10.4 慢请求 / 异常

通过 `error.protocol` / `error.codec` 事件 + 日志可定位：

```php
->on('error.protocol', function (array $args) use ($logger) {
    $logger->error('protocol error', $args);
})
->on('error.codec', function (array $args) use ($logger) {
    $logger->error('codec error', $args);
});
```

## 11. 灰度发布

### 11.1 蓝绿

1. 部署新版本到 `messaging-green`（新端口如 9080）
2. Nginx upstream 切到绿色 → 观察 5 分钟
3. 稳定后关闭蓝色（`messaging@ws` 旧实例）

```nginx
upstream messaging_ws {
    server 127.0.0.1:8080 weight=1;  # 蓝
    server 127.0.0.1:9080 weight=0;  # 绿（先 weight=0）
}
```

### 11.2 金丝雀

Nginx + Lua 或 Envoy 按请求比例分发。

## 12. 常见问题

### 12.1 "Address already in use"

```bash
sudo lsof -i :8080
sudo systemctl stop messaging@ws
```

### 12.2 "Permission denied" 绑定 80/443 端口

`Dockerfile` 已使用 `setcap`，裸机请：

```bash
sudo setcap 'cap_net_bind_service=+ep' $(which php)
```

### 12.3 上传大文件失败

```php
'websocket' => [
    'max_frame_size' => 16 * 1024 * 1024,  // 16 MiB
]
```

同时调整 `php.ini`：

```ini
upload_max_filesize = 16M
post_max_size = 16M
```

### 12.4 连接耗尽（too many open files）

```bash
ulimit -n 65535
# /etc/security/limits.d/99-messaging.conf 永久生效
```

### 12.5 跨节点广播不生效

确认 `cluster.enabled = true` 并所有节点共享同一 Redis：

```php
'cluster' => [
    'enabled' => true,
    'driver'  => 'redis',
],
'pubsub' => [
    'default' => 'redis',
    'redis' => [
        'host' => '10.0.0.1',
        'port' => 6379,
    ],
],
```

### 12.6 daemon 模式启动后 status 显示 stopped (stale)

PID 文件存在但进程已死，常见原因：

1. 父进程退出但子进程未清理 PID → 检查日志中是否有 fatal error
2. 端口被占用 → `sudo lsof -i :8080`
3. autoload 路径错误 → `php bin/messaging self-check`

## 12. 下一步

- 架构：[docs/architecture.md](./architecture.md)
- 配置：[docs/configuration.md](./configuration.md)
- 快速开始：[docs/quick-start.md](./quick-start.md)
- 迁移：[docs/migration.md](./migration.md)
