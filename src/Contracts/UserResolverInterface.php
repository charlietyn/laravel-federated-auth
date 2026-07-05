<?php
namespace Ronu\LaravelFederatedAuth\Contracts;
use Illuminate\Contracts\Auth\Authenticatable; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
interface UserResolverInterface { public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable; public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable; public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable; }
