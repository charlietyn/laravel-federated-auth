<?php
namespace Ronu\LaravelFederatedAuth\Contracts;
use Illuminate\Contracts\Auth\Authenticatable; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
interface RoleMapperInterface { public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void; }
