<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;

interface UserStatusCheckerInterface
{
    public function ensureCanLogin(Authenticatable $user, AuthContext $context): void;
}
