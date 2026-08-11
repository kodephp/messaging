# syntax=docker/dockerfile:1.7
# =====================================================================
# kode/messaging 多阶段 Dockerfile
# ---------------------------------------------------------------------
# 用法：
#   docker build -t kode/messaging:latest -f docker/Dockerfile .
#   docker run -d --name messaging -p 8080:8080 kode/messaging:latest
# 多阶段构建：builder 安装 composer 依赖，runtime 仅保留必要文件
# =====================================================================

# ----------- 阶段 1：构建依赖 -----------
FROM php:8.3-cli-alpine AS builder

# 基础工具
RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        oniguruma-dev \
        openssl-dev \
        linux-headers \
    && docker-php-ext-install \
        sockets \
        bcmath \
        pcntl \
        posix \
        opcache

# 安装 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 先拷贝 composer.json 单独构建（缓存层）
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --optimize-autoloader

# 再拷贝源码
COPY . .

# 生成最终 autoload
RUN composer dump-autoload --optimize --classmap-authoritative

# ----------- 阶段 2：运行时 -----------
FROM php:8.3-cli-alpine AS runtime

LABEL org.opencontainers.image.title="kode/messaging" \
      org.opencontainers.image.description="Unified long-connection/real-time messaging library for PHP 8.2+" \
      org.opencontainers.image.source="https://github.com/kodephp/messaging" \
      org.opencontainers.image.licenses="MIT"

# 运行时必要扩展（与 builder 保持一致）
RUN apk add --no-cache \
        icu-dev \
        oniguruma-dev \
        openssl-dev \
        libcap \
    && docker-php-ext-install \
        sockets \
        bcmath \
        pcntl \
        posix \
        opcache \
    && apk del icu-dev oniguruma-dev openssl-dev

# 允许 php 在 1024 以下端口（如 WebTransport 4433）
RUN setcap 'cap_net_bind_service=+ep' /usr/local/bin/php || true

# 优化 opcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/php.ini     /usr/local/etc/php/php.ini

# 创建非 root 用户
RUN addgroup -g 1000 -S kode \
    && adduser  -u 1000 -S kode -G kode

WORKDIR /app
COPY --from=builder --chown=kode:kode /app /app

# 数据目录（var / log / runtime）
RUN mkdir -p /app/var/log /app/var/run \
    && chown -R kode:kode /app/var

USER kode

# 默认入口
ENTRYPOINT ["php", "bin/messaging"]
CMD ["help"]

# 健康检查（仅当以 server 模式启动时使用）
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(@fsockopen(getenv('MESSAGING_HEALTH_HOST') ?: '127.0.0.1', (int)(getenv('MESSAGING_HEALTH_PORT') ?: 8080)) ? 0 : 1);"

# 默认暴露端口
EXPOSE 8080 8081 1883 8082 8083 5683 4222 61613 50051 4433 1935
