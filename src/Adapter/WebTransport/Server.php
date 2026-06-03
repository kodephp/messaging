<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebTransport;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\WebTransportException;

/**
 * WebTransport 服务端（HTTP/2-fallback 占位）
 *
> 真实 WebTransport 服务端需要 HTTP/3 终结（QUIC + HTTP/3），
> 推荐使用 aioquic / msquic / Cloudflare quiche 等外部进程。
>
> 本实现作为"业务 API 挂载点"存在：
>  - 业务可通过 registerDatagramHandler / registerBidirectionalHandler 注册回调
>  - 由外部 HTTP/3 后端调用本 Server 暴露的方法，把真实事件转发到业务
 */
final class Server extends AbstractAdapter
{
    /** @var resource|null */
    private $socket = null;
    private ?\Kode\Messaging\Server\Builder $builder = null;
    /** @var array<string, callable(string $payload, array $meta): void> */
    private array $bidiHandlers = [];
    /** @var array<string, callable(string $payload, array $meta): void> */
    private array $unidiHandlers = [];
    /** @var array<string, callable(string $payload, array $meta): void> */
    private array $dgramHandlers = [];

    public static function scheme(): string
    {
        return 'webtransport';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function setBuilder(\Kode\Messaging\Server\Builder $builder): void
    {
        $this->builder = $builder;
        $builder->on('wt.dispatched', function () { /* 业务可订阅 */ });
    }

    public function builder(): ?\Kode\Messaging\Server\Builder
    {
        return $this->builder;
    }

    public function onBidirectional(string $session, callable $cb): self
    {
        $this->bidiHandlers[$session] = $cb;
        return $this;
    }

    public function onUnidirectional(string $session, callable $cb): self
    {
        $this->unidiHandlers[$session] = $cb;
        return $this;
    }

    public function onDatagram(string $session, callable $cb): self
    {
        $this->dgramHandlers[$session] = $cb;
        return $this;
    }

    protected function defaultConfig(): array
    {
        return [
            'http3_backend' => null, // 实际 HTTP/3 后端地址（HTTP/JSON 转发）
        ];
    }

    public function listen(string $host, int $port): void
    {
        // 占位监听：实际业务应通过 HTTP/3 后端调用本 Server 的方法
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->socket === false) {
            throw WebTransportException::sessionError("listen 失败: {$errstr}");
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("WebTransport 占位监听在 {$host}:{$port}（请使用 HTTP/3 后端）");
    }

    public function run(): void
    {
        $this->running = true;
        // @phpstan-ignore-next-line
        while ($this->running) {
            $new = @stream_socket_accept($this->socket, 0);
            if ($new === false) {
                usleep(1_000);
                continue;
            }
            // 占位响应：告知业务已接入但需要 HTTP/3 后端
            $body = "WebTransport over HTTP/3 required. Use a HTTP/3 backend (aioquic / msquic).";
            $resp = "HTTP/1.1 426 Upgrade Required\r\n"
                . "Content-Type: text/plain\r\n"
                . "Content-Length: " . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n" . $body;
            @fwrite($new, $resp);
            @fclose($new);
        }
    }

    public function connect(array $config): ConnectionInterface
    {
        throw new \LogicException('WebTransport Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('webtransport', self::class);
    }

    /**
     * 由外部 HTTP/3 后端调用，把一个双向流事件投递给业务。
     */
    public function dispatchBidirectional(string $session, string $payload, array $meta = []): void
    {
        $cb = $this->bidiHandlers[$session] ?? null;
        if ($cb !== null) {
            try {
                $cb($payload, $meta);
            } catch (\Throwable) {
            }
        }
    }

    public function dispatchUnidirectional(string $session, string $payload, array $meta = []): void
    {
        $cb = $this->unidiHandlers[$session] ?? null;
        if ($cb !== null) {
            try {
                $cb($payload, $meta);
            } catch (\Throwable) {
            }
        }
    }

    public function dispatchDatagram(string $session, string $payload, array $meta = []): void
    {
        $cb = $this->dgramHandlers[$session] ?? null;
        if ($cb !== null) {
            try {
                $cb($payload, $meta);
            } catch (\Throwable) {
            }
        }
    }
}
