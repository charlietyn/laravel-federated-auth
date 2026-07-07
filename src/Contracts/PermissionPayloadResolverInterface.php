<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;

interface PermissionPayloadResolverInterface
{
    /**
     * Resolve an optional permissions payload for the authenticated local user.
     *
     * Implementations must be safe-by-default: when permissions cannot be resolved,
     * return an empty array instead of breaking authentication.
     */
    public function resolve(Authenticatable $user, AuthContext $context): array;
}
