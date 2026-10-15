<?php

// Swow PECL 扩展符号桩（phpstan 静态分析用；运行期由扩展真实提供）

namespace Swow;

class Socket
{
    public const TYPE_TCP = 1;
    public const TYPE_UDP = 2;
    public const TYPE_UNIX = 3;
    public const READ_DEFAULT = 0;
    public const READ_PEEK = 1;

    public function __construct(int $domain = self::TYPE_TCP, int $type = 0, int $protocol = 0) {}

    public function bind(string $address, int $port = 0): void {}

    public function listen(int $backlog = 0): void {}

    public function accept(?Socket $socket = null, ?float $timeout = null): Socket {}

    public function connect(string $address, int $port = 0, ?float $timeout = null): void {}

    public function recv(int $length = 0, int $flags = self::READ_DEFAULT, ?float $timeout = null): string {}

    public function recvString(int $length = 0, int $flags = self::READ_DEFAULT, ?float $timeout = null): string {}

    public function send(string $data, ?float $timeout = null): int {}

    public function sendString(string $data, ?float $timeout = null): int {}

    public function close(): void {}

    public function setNonBlocking(bool $enable = true): void {}

    public function setBlocking(bool $enable = true): void {}

    public function setTcpNodelay(bool $enable = true): void {}

    public function setDeadline(?float $timeout): void {}

    public function setRecvTimeout(?float $timeout): void {}

    public function setSendTimeout(?float $timeout): void {}

    public function checkLiveness(): void {}

    public function getLocalName(): array {}

    public function getPeerName(): array {}

    public function getPeerAddress(): string {}

    public function getPeerPort(): int {}

    public static function select(array &$read, array &$write, ?array &$except = null, ?float $timeout = null): int {}
}

namespace Swow;

class Coroutine
{
    public static function sleep(float $seconds): void {}

    public static function yield(): void {}
}
