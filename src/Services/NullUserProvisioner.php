<?php
namespace Ronu\LaravelFederatedAuth\Services;
use Illuminate\Contracts\Auth\Authenticatable; use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity; use Ronu\LaravelFederatedAuth\Exceptions\UserProvisioningNotConfiguredException;
class NullUserProvisioner implements UserProvisionerInterface { public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable { throw new UserProvisioningNotConfiguredException('Auto provisioning is enabled but no UserProvisionerInterface implementation was configured.'); } }
