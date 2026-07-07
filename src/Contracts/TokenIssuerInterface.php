<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;

interface TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult;
}
