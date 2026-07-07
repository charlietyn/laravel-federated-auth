<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

class NoopRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void {}
}
