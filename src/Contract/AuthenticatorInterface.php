<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 鉴权器接口
 *
 * 鉴权器在握手/连接阶段被调用，校验通过则返回携带业务身份（userId、scopes 等）的 AuthContext。
 * 失败抛出 MessagingException（code=4xx）。
 */
interface AuthenticatorInterface
{
    /**
     * 鉴权。
     *
     * @param array<string, string> $credentials 协议握手时携带的凭据
     *                                          （HTTP Header、URL query、WebSocket subprotocol 等）
     * @return AuthContext 鉴权成功后的业务上下文
     * @throws \Kode\Messaging\Exception\MessagingException 鉴权失败
     */
    public function authenticate(array $credentials): AuthContext;
}
