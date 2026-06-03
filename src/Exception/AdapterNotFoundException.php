<?php

declare(strict_types=1);

namespace Kode\Messaging\Exception;

/**
 * 协议适配器未注册
 */
class AdapterNotFoundException extends MessagingException
{
    public static function forScheme(string $scheme, array $registered = []): self
    {
        return new self(
            "协议适配器未注册: {$scheme}",
            4040,
            [
                'scheme'     => $scheme,
                'registered' => $registered,
            ],
        );
    }
}
