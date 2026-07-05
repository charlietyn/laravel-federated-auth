<?php
namespace Ronu\LaravelFederatedAuth\Contracts;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
interface IdentityProviderAdapterInterface { public function name(): string; public function supports(string $provider): bool; public function redirectUrl(AuthContext $context): string; public function userFromCallback(AuthContext $context): ExternalIdentity; public function userFromToken(string $token, AuthContext $context): ExternalIdentity; }
