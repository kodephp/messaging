<?php

declare(strict_types=1);

namespace Kode\Messaging\Message;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Support\IdGenerator;

/**
 * 协议无关消息体（不可变）
 *
 * readonly class 是 PHP 8.2+ 特性；不允许修改属性。
 * 修改必须通过 with*() 方法返回新对象。
 */
final readonly class Message implements MessageInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $context  附加上下文（trace id、user id 等）
     */
    public function __construct(
        public string $messageId,
        public ?string $eventName,
        public ?string $topic,
        public mixed $body,
        public string $rawBytes,
        public array $headers,
        public int $qos,
        public bool $binary,
        public bool $retain,
        public string $proto,
        public int $timestamp,
        public array $context = [],
    ) {
    }

    public function id(): string
    {
        return $this->messageId;
    }

    public function event(): ?string
    {
        return $this->eventName;
    }

    public function topic(): ?string
    {
        return $this->topic;
    }

    public function payload(): mixed
    {
        return $this->body;
    }

    public function raw(): string
    {
        return $this->rawBytes;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function qos(): int
    {
        return $this->qos;
    }

    public function isBinary(): bool
    {
        return $this->binary;
    }

    public function isRetain(): bool
    {
        return $this->retain;
    }

    public function protocol(): string
    {
        return $this->proto;
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function withPayload(mixed $payload): self
    {
        return new self(
            $this->messageId,
            $this->eventName,
            $this->topic,
            $payload,
            $this->rawBytes,
            $this->headers,
            $this->qos,
            $this->binary,
            $this->retain,
            $this->proto,
            $this->timestamp,
            $this->context,
        );
    }

    public function withEvent(?string $event): self
    {
        return new self(
            $this->messageId,
            $event,
            $this->topic,
            $this->body,
            $this->rawBytes,
            $this->headers,
            $this->qos,
            $this->binary,
            $this->retain,
            $this->proto,
            $this->timestamp,
            $this->context,
        );
    }

    /**
     * 工厂：从原始字节创建。
     */
    public static function fromRaw(
        string $raw,
        string $protocol,
        ?string $event = null,
        ?string $topic = null,
        int $qos = 0,
        bool $binary = false,
        bool $retain = false,
        array $headers = [],
        array $context = [],
    ): self {
        return new self(
            IdGenerator::next('msg'),
            $event,
            $topic,
            $raw,
            $raw,
            $headers,
            $qos,
            $binary,
            $retain,
            $protocol,
            (int)(microtime(true) * 1000),
            $context,
        );
    }

    /**
     * 工厂：从已解码 payload 创建。
     */
    public static function of(
        mixed $payload,
        string $protocol,
        ?string $event = null,
        ?string $topic = null,
        int $qos = 0,
        bool $binary = false,
        bool $retain = false,
        array $headers = [],
        array $context = [],
    ): self {
        $raw = is_string($payload) ? $payload : (string)json_encode($payload);
        return new self(
            IdGenerator::next('msg'),
            $event,
            $topic,
            $payload,
            $raw,
            $headers,
            $qos,
            $binary,
            $retain,
            $protocol,
            (int)(microtime(true) * 1000),
            $context,
        );
    }
}
