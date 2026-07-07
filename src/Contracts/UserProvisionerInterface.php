<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

interface UserProvisionerInterface
{
    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable;
}
