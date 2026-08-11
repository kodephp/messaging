<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\LongPolling;

use function hexdec;

use Kode\Messaging\Exception\LongPollingException;
use Kode\Messaging\Message\Message as Msg;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Long-Polling 客户端连接
 *
 * 每次 poll() 内部完成一次完整的"建连-请求-响应"生命周期。
 * 业务侧只需注册 onMessage() 回调与可选的 onError() 回调。
 */
final class LongPollingClientConnection extends LongPollingConnection
{
    /** @var null|callable(Msg):void */
    private $messageHandler = null;

    /** @var null|callable(Throwable):void */
    private $errorHandler = null;

    private bool $stop = false;

    /** @var array<string, mixed> */
    private array $config;

    private LoggerInterface $logger;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $connId,
        string $_protocol,
        string $remoteAddress,
        array $config,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($connId, $remoteAddress, null, []);
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 注册消息回调。
     */
    public function onMessage(callable $handler): self
    {
        $this->messageHandler = $handler;

        return $this;
    }

    /**
     * 注册错误回调。
     */
    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * 设置 HTTP 方法。
     */
    public function setMethod(string $method): self
    {
        $this->config['method'] = strtoupper($method);

        return $this;
    }

    /**
     * 设置单个请求头。
     */
    public function setHeader(string $name, string $value): self
    {
        $this->config['headers'][$name] = $value;

        return $this;
    }

    /**
     * 设置请求体。
     */
    public function setBody(string $body, ?string $contentType = null): self
    {
        $this->config['body'] = $body;
        if ($contentType !== null) {
            $this->config['headers']['Content-Type'] = $contentType;
        }

        return $this;
    }

    /**
     * 设置请求路径。
     */
    public function setPath(string $path): self
    {
        $this->config['path'] = $path;

        return $this;
    }

    /**
     * 设置查询参数。
     *
     * @param array<string, int|string> $query
     */
    public function setQuery(array $query): self
    {
        $this->config['query'] = array_map('strval', $query);

        return $this;
    }

    /**
     * 持续轮询主循环。
     */
    public function poll(): void
    {
        $retries = 0;
        $maxRetries = (int) ($this->config['max_retries'] ?? 0);
        $delay = (int) ($this->config['retry_delay_ms'] ?? 1_000);

        while (! $this->stop) {
            try {
                $msg = $this->doOnce();
                if ($msg !== null) {
                    $this->dispatch($msg);
                }
                $retries = 0;
            } catch (Throwable $e) {
                $retries++;
                $this->logger->warning('long-polling poll error', ['error' => $e->getMessage()]);
                if ($this->errorHandler !== null) {
                    try {
                        ($this->errorHandler)($e);
                    } catch (Throwable) {
                    }
                }
                if ($maxRetries > 0 && $retries >= $maxRetries) {
                    throw $e;
                }
                usleep($delay * 1_000);
            }
        }
    }

    /**
     * 停止轮询循环。
     */
    public function stop(): void
    {
        $this->stop = true;
        $this->close();
    }

    /**
     * 关闭连接（客户端版本）。
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->stop = true;
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return ! $this->stop && $this->open;
    }

    /**
     * 客户端 send 没有意义（HTTP 是请求-响应，不是双向）。
     */
    public function send(mixed $payload, array $options = []): bool
    {
        throw new LogicException('LongPolling 客户端连接不支持 send()，请使用 withBody()');
    }

    /**
     * 执行一次 HTTP 请求并返回响应消息。
     */
    private function doOnce(): Msg
    {
        $errno = 0;
        $errstr = '';
        $remote = ($this->config['tls'] ?? false ? 'tls' : 'tcp').'://'
            .$this->config['host'].':'.$this->config['port'];
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) ($this->config['read_timeout'] ?? 30),
        );
        if ($socket === false) {
            throw LongPollingException::listenFailed(
                (string) $this->config['host'],
                (int) $this->config['port'],
                (string) $errstr,
            );
        }

        try {
            stream_set_timeout($socket, (int) ($this->config['read_timeout'] ?? 30));
            $this->writeRequest($socket);

            $response = $this->readResponse($socket);
            $body = $response['body'];
            $headers = $response['headers'];
            $status = $response['status'];

            $contentType = $headers['content-type'] ?? '';
            $payload = $this->decode($body, $contentType);

            return Msg::fromRaw(
                $body,
                'long-polling',
                topic: $this->config['query']['topic'] ?? null,
                context: [
                    'status' => $status,
                    'headers' => $headers,
                ],
            )->withPayload($payload);
        } finally {
            @fclose($socket);
        }
    }

    private function writeRequest($socket): void
    {
        $method = (string) ($this->config['method'] ?? 'GET');
        $path = (string) ($this->config['path'] ?? '/');
        $query = (array) ($this->config['query'] ?? []);
        $queryString = http_build_query($query);
        $target = $path.($queryString !== '' ? '?'.$queryString : '');

        $body = (string) ($this->config['body'] ?? '');
        $headers = (array) ($this->config['headers'] ?? []);

        $hostHeader = $this->config['host'].':'.$this->config['port'];
        $defaultHeaders = [
            'Host' => $hostHeader,
            'User-Agent' => 'kode-messaging/1.0',
            'Accept' => 'application/json',
            'Connection' => 'close',
        ];
        if ($body !== '') {
            $defaultHeaders['Content-Length'] = (string) strlen($body);
        }
        $headers = array_replace($defaultHeaders, $headers);

        $packet = "{$method} {$target} HTTP/1.1\r\n";
        foreach ($headers as $k => $v) {
            $packet .= "{$k}: {$v}\r\n";
        }
        $packet .= "\r\n".$body;

        $bytes = @fwrite($socket, $packet);
        if ($bytes === false) {
            throw LongPollingException::responseWriteFailed('write request failed');
        }
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function readResponse($socket): array
    {
        $headerBuf = '';
        while (! feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                break;
            }
            $headerBuf .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }

        $lines = explode("\r\n", trim($headerBuf));
        $statusLine = array_shift($lines) ?: '';
        $status = 200;
        if (preg_match('#HTTP/\d+\.\d+ (\d+)#', $statusLine, $m)) {
            $status = (int) $m[1];
        }

        $headers = [];
        foreach ($lines as $h) {
            if ($h === '') {
                continue;
            }
            $pos = strpos($h, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($h, 0, $pos)))] = trim(substr($h, $pos + 1));
        }

        // 通过 Content-Length 或 chunked 读取 body
        $body = '';
        if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
            $body = $this->readChunked($socket);
        } else {
            $contentLength = (int) ($headers['content-length'] ?? 0);
            $remaining = $contentLength;
            while ($remaining > 0 && ! feof($socket)) {
                $chunk = fread($socket, min($remaining, 8192));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @param resource $socket
     */
    private function readChunked($socket): string
    {
        $body = '';
        while (! feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === '0') {
                break;
            }
            $size = (int) hexdec($line);
            $remaining = $size;
            while ($remaining > 0 && ! feof($socket)) {
                $chunk = fread($socket, min($remaining, 8192));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= \strlen($chunk);
            }
            // 读 \r\n
            fgets($socket, 4);
        }

        return $body;
    }

    private function decode(string $body, string $contentType): mixed
    {
        if ($body === '') {
            return null;
        }
        if (stripos($contentType, 'application/json') !== false) {
            try {
                return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $body;
            }
        }

        return $body;
    }

    private function dispatch(Msg $msg): void
    {
        if ($this->messageHandler !== null) {
            try {
                ($this->messageHandler)($msg);
            } catch (Throwable $e) {
                $this->logger->error('long-polling handler error', ['error' => $e->getMessage()]);
            }
        }
    }
}
