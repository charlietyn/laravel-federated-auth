<?php

namespace Ronu\LaravelFederatedAuth\Services\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;

class NullPermissionPayloadResolver implements PermissionPayloadResolverInterface
{
    public function resolve(Authenticatable $user, AuthContext $context): array
    {
        return [];
    }
}
