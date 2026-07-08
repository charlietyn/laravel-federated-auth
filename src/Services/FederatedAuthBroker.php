<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Ronu\LaravelFederatedAuth\Contracts\IdentityLinkRepositoryInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Events\ExternalAccountLinked;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginSucceeded;
use Ronu\LaravelFederatedAuth\Events\ExternalUserProvisioned;
use Ronu\LaravelFederatedAuth\Exceptions\EmailNotVerifiedException;
use Ronu\LaravelFederatedAuth\Exceptions\EmailRequiredException;
use Ronu\LaravelFederatedAuth\Exceptions\IdentityAlreadyLinkedException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\LastIdentityUnlinkDeniedException;
use Ronu\LaravelFederatedAuth\Exceptions\PackageDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\UserProvisioningNotConfiguredException;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

class FederatedAuthBroker
{
    public function __construct(
        private readonly IdentityProviderRegistryInterface $providers,
        private readonly IdentityLinkRepositoryInterface $links,
        private readonly UserResolverInterface $users,
        private readonly UserProvisionerInterface $provisioner,
        private readonly TokenIssuerInterface $tokens,
        private readonly UserStatusCheckerInterface $statusChecker,
        private readonly RoleMapperInterface $roleMapper,
        private readonly OAuthStateStoreInterface $states,
    ) {}

    public function redirectUrl(string $provider, AuthContext $context): string
    {
        $this->ensurePackageEnabled();
        ProviderConfig::get($provider);

        return $this->providers->adapterFor($provider)->redirectUrl($context);
    }

    public function loginFromCallback(string $provider, AuthContext $context): AuthResult
    {
        $this->ensurePackageEnabled();

        $context = $this->contextForCallback($provider, $context);
        $identity = $this->providers->adapterFor($provider)->userFromCallback($context);

        return $this->authenticateIdentity($identity, $context);
    }

    public function loginFromToken(string $provider, string $token, AuthContext $context): AuthResult
    {
        $this->ensurePackageEnabled();
        $identity = $this->providers->adapterFor($provider)->userFromToken($token, $context);

        return $this->authenticateIdentity($identity, $context);
    }

    public function linkIdentity(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): AuthResult
    {
        $this->ensurePackageEnabled();
        $cfg = ProviderConfig::get($identity->provider);
        $this->validateIdentity($identity, $cfg, $context);
        $uid = $user->getAuthIdentifier();
        $existing = $this->links->findByProviderIdentity($identity->provider, $identity->providerUserId, $context);

        if ($existing && (string) $existing->userId !== (string) $uid) {
            throw new IdentityAlreadyLinkedException('This external identity is already linked to another local user.');
        }

        $linked = $this->links->findByUserAndProvider($uid, $identity->provider, $context);

        if ($linked) {
            $this->links->touch($linked, $identity, $context);
        } else {
            $this->links->create($uid, $identity, $context);
            Event::dispatch(new ExternalAccountLinked($user, $identity, $context));
        }

        $this->roleMapper->sync($user, $identity, $context);
        $result = $this->tokens->issue($user, $context);

        return new AuthResult($user, $result->tokens, $identity, false, true, $result->metadata);
    }

    public function unlink(Authenticatable $user, string $provider, AuthContext $context): void
    {
        $this->ensurePackageEnabled();
        ProviderConfig::get($provider);
        $uid = $user->getAuthIdentifier();
        $linked = $this->links->findByUserAndProvider($uid, $provider, $context);

        if (! $linked) {
            return;
        }

        if (config('federated-auth.security.deny_unlink_last_identity_without_password', true)) {
            $count = $this->links->countForUser($uid, $context);
            $passwordColumn = config('federated-auth.user.columns.password', 'password');
            $hasPassword = filled(data_get($user, $passwordColumn));

            if ($count <= 1 && ! $hasPassword) {
                throw new LastIdentityUnlinkDeniedException('Cannot unlink the last external identity from a user without a local password.');
            }
        }

        $this->links->delete($linked, $context);
    }

    public function authenticateIdentity(ExternalIdentity $identity, AuthContext $context): AuthResult
    {
        $cfg = ProviderConfig::get($identity->provider);
        $this->validateIdentity($identity, $cfg, $context);
        $linked = $this->links->findByProviderIdentity($identity->provider, $identity->providerUserId, $context);

        if ($linked) {
            $user = $this->users->resolveById($linked->userId, $context);

            if (! $user) {
                throw new ProviderDisabledException('External identity exists but local user cannot be resolved.');
            }

            $this->statusChecker->ensureCanLogin($user, $context);
            $this->links->touch($linked, $identity, $context);
            $this->roleMapper->sync($user, $identity, $context);

            return $this->success($user, $identity, $context, false, false);
        }

        $user = null;

        if (($cfg['allow_email_linking'] ?? false) === true) {
            if (config('federated-auth.security.deny_unverified_email_linking', true) && ! $identity->emailVerified) {
                throw new EmailNotVerifiedException('Email linking requires a verified provider email.');
            }

            $user = $this->users->resolveByEmail($identity, $context);
        }

        $wasProvisioned = false;

        if (! $user) {
            if (! ($cfg['auto_provision'] ?? false)) {
                throw new UserProvisioningNotConfiguredException('External identity is not linked and auto provisioning is disabled.');
            }

            $user = $this->provisioner->provision($identity, $context);
            $wasProvisioned = true;
            Event::dispatch(new ExternalUserProvisioned($user, $identity, $context));
        }

        $this->statusChecker->ensureCanLogin($user, $context);
        $this->links->create($user->getAuthIdentifier(), $identity, $context);
        $this->roleMapper->sync($user, $identity, $context);

        return $this->success($user, $identity, $context, $wasProvisioned, true);
    }

    private function contextForCallback(string $provider, AuthContext $context): AuthContext
    {
        if (! (bool) config('federated-auth.security.oauth_state.enabled', true)) {
            return $context;
        }

        if ($context->authorizationState) {
            return $context->withAuthorizationState($context->authorizationState);
        }

        $incomingState = $context->request?->query('state')
            ?? $context->request?->input('state')
            ?? $context->state;

        if (! is_string($incomingState) || $incomingState === '') {
            throw new InvalidOAuthStateException('OAuth callback did not include a state value.');
        }

        $state = $this->states->consume($provider, $incomingState, $context->request ?: request());

        return $context->withAuthorizationState($state);
    }

    private function validateIdentity(ExternalIdentity $identity, array $cfg, AuthContext $context): void
    {
        if ($identity->requiresEmailButMissing($cfg['require_email'] ?? false)) {
            throw new EmailRequiredException('The provider did not return an email address.');
        }

        if (($cfg['require_verified_email'] ?? false) && ! $identity->emailVerified) {
            throw new EmailNotVerifiedException('The provider email is not verified.');
        }

        $allowed = $cfg['allowed_user_types'] ?? [];
        $requested = $context->userType;

        if ($requested && $allowed && ! in_array($requested, $allowed, true)) {
            throw new ProviderDisabledException("User type [$requested] is not allowed for provider [{$identity->provider}].");
        }

        if (config('federated-auth.security.prevent_admin_auto_provision', true)) {
            $admin = config('federated-auth.security.admin_user_types', []);

            if ($requested && in_array($requested, $admin, true)) {
                throw new ProviderDisabledException('Admin users cannot be auto-provisioned through federated auth.');
            }
        }
    }

    private function success(
        Authenticatable $user,
        ExternalIdentity $identity,
        AuthContext $context,
        bool $wasProvisioned,
        bool $wasLinked,
    ): AuthResult {
        $result = $this->tokens->issue($user, $context);
        $final = new AuthResult($user, $result->tokens, $identity, $wasProvisioned, $wasLinked, $result->metadata);
        Event::dispatch(new ExternalLoginSucceeded($user, $identity, $context, $final));

        return $final;
    }

    private function ensurePackageEnabled(): void
    {
        if (! config('federated-auth.enabled', true)) {
            throw new PackageDisabledException('Federated authentication is globally disabled.');
        }
    }
}
