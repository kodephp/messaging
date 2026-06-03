<?php

declare(strict_types=1);

namespace Kode\Messaging\Contract;

/**
 * 鉴权成功后的业务身份上下文
 */
final class AuthContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly ?string $userId = null,
        public readonly array $scopes = [],
        public readonly array $attributes = [],
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'userId'     => $this->userId,
            'scopes'     => $this->scopes,
            'attributes' => $this->attributes,
        ];
    }
}
