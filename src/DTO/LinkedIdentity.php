<?php

namespace Ronu\LaravelFederatedAuth\DTO;

final class LinkedIdentity
{
    public function __construct(
        public readonly string|int $id,
        public readonly string|int $userId,
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $tenantId = null,
        public readonly array $attributes = [],
    ) {}
}
