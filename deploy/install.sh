#!/usr/bin/env bash
# =====================================================================
# kode/messaging 部署脚本
# ---------------------------------------------------------------------
# 用法：
#   sudo ./deploy/install.sh                  # 安装到 /srv/messaging
#   sudo ./deploy/install.sh --target=/opt     # 安装到自定义目录
#   sudo PROTOCOL=ws ./deploy/install.sh      # 仅启用 WebSocket
#
# 该脚本会：
#   1. 同步代码到目标目录
#   2. composer install --no-dev
#   3. 生成 systemd 单元 / supervisor 配置（按系统自动选择）
#   4. 注册并启动服务
# =====================================================================

set -euo pipefail

# -------- 默认值 --------
TARGET="/srv/messaging"
SOURCE="$(cd "$(dirname "$0")/.." && pwd)"
PROTOCOL="${PROTOCOL:-ws}"
SERVICE_USER="${SERVICE_USER:-www-data}"
USE_SUPERVISOR="${USE_SUPERVISOR:-auto}"  # auto | systemd | supervisor
ENABLE_NGINX="${ENABLE_NGINX:-false}"
PORT="${PORT:-8080}"

# -------- 解析参数 --------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --target=*) TARGET="${1#*=}" ;;
        --target) TARGET="$2"; shift ;;
        --protocol=*) PROTOCOL="${1#*=}" ;;
        --port=*) PORT="${1#*=}" ;;
        --user=*) SERVICE_USER="${1#*=}" ;;
        --supervisor) USE_SUPERVISOR="supervisor" ;;
        --systemd) USE_SUPERVISOR="systemd" ;;
        --nginx) ENABLE_NGINX="true" ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *) echo "未知参数: $1" >&2; exit 1 ;;
    esac
    shift
done

[[ $EUID -ne 0 ]] && { echo "请使用 root 或 sudo 运行"; exit 1; }

echo "==> 部署参数"
echo "    source   : $SOURCE"
echo "    target   : $TARGET"
echo "    user     : $SERVICE_USER"
echo "    protocol : $PROTOCOL"
echo "    port     : $PORT"

# -------- 1. 同步代码 --------
echo "==> 同步代码到 $TARGET"
mkdir -p "$TARGET"
rsync -a --delete \
    --exclude='vendor' --exclude='var/log/*' --exclude='.git' \
    "$SOURCE/" "$TARGET/"

# -------- 2. 安装依赖 --------
echo "==> composer install"
if ! command -v composer >/dev/null; then
    echo "未检测到 composer，请先安装：https://getcomposer.org/download/" >&2
    exit 1
fi
cd "$TARGET"
composer install --no-dev --no-interaction --optimize-autoloader --no-progress

# 目录权限
mkdir -p var/log var/run
chown -R "$SERVICE_USER:$SERVICE_USER" var

# -------- 3. 选择进程管理器 --------
if [[ "$USE_SUPERVISOR" == "auto" ]]; then
    if systemctl --version >/dev/null 2>&1; then
        USE_SUPERVISOR="systemd"
    elif command -v supervisord >/dev/null 2>&1; then
        USE_SUPERVISOR="supervisor"
    else
        echo "未检测到 systemd 或 supervisor，跳过服务注册" >&2
        USE_SUPERVISOR="none"
    fi
fi

# -------- 4. 注册服务 --------
case "$USE_SUPERVISOR" in
    systemd)
        echo "==> 注册 systemd 单元 messaging@${PROTOCOL}.service"
        sed -e "s|/srv/messaging|$TARGET|g" \
            -e "s|--protocol=%i|--protocol=$PROTOCOL|" \
            -e "s|--port=\${PORT}|--port=$PORT|" \
            "$SOURCE/deploy/systemd/messaging@.service" \
            > "/etc/systemd/system/messaging@${PROTOCOL}.service"

        systemctl daemon-reload
        systemctl enable --now "messaging@${PROTOCOL}.service"
        systemctl status --no-pager "messaging@${PROTOCOL}.service" || true
        ;;

    supervisor)
        echo "==> 注册 supervisor 程序 messaging-${PROTOCOL}"
        mkdir -p /var/log/messaging
        sed -e "s|/srv/messaging|$TARGET|g" \
            -e "s|programs=ws,sse,mqtt,nats,grpc|programs=$PROTOCOL|" \
            "$SOURCE/deploy/supervisor/messaging.conf" \
            > "/etc/supervisor/conf.d/messaging-${PROTOCOL}.conf"

        supervisorctl reread
        supervisorctl update
        supervisorctl restart "messaging-${PROTOCOL}" || true
        ;;

    none)
        echo "==> 跳过服务注册，可手动执行："
        echo "    php $TARGET/bin/messaging start --protocol=$PROTOCOL --port=$PORT"
        ;;
esac

# -------- 5. 可选 Nginx --------
if [[ "$ENABLE_NGINX" == "true" ]]; then
    if [[ -d /etc/nginx/conf.d ]]; then
        echo "==> 复制 Nginx 反代模板到 /etc/nginx/conf.d/messaging.conf.example"
        cp "$SOURCE/deploy/nginx/messaging.conf" /etc/nginx/conf.d/messaging.conf.example
        echo "    请根据实际域名修改后启用"
    fi
fi

# -------- 6. 自检 --------
echo "==> 运行环境自检"
php "$TARGET/bin/messaging" self-check

echo "==> 部署完成"
