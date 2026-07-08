<?php

namespace Ronu\LaravelFederatedAuth\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Throwable;

class ExternalLoginFailed
{
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?ExternalIdentity $identity,
        public readonly AuthContext $context,
        public readonly Throwable $exception,
    ) {}
}
