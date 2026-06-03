<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\Codec;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;

/**
 * 透传编解码：不做任何解码/编码。
 *
 * 适用于：业务层已经自己处理了 JSON / MsgPack / protobuf。
 */
final class RawCodec implements MiddlewareInterface
{
    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        return $next($message);
    }
}
