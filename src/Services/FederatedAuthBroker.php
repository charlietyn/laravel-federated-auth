<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Ronu\LaravelFederatedAuth\Contracts\AuthenticationTransactionInterface;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
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
use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;
use Ronu\LaravelFederatedAuth\Events\ExternalAccountLinked;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginFailed;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginSucceeded;
use Ronu\LaravelFederatedAuth\Events\ExternalRedirectIssued;
use Ronu\LaravelFederatedAuth\Events\ExternalUserProvisioned;
use Ronu\LaravelFederatedAuth\Exceptions\EmailNotVerifiedException;
use Ronu\LaravelFederatedAuth\Exceptions\EmailRequiredException;
use Ronu\LaravelFederatedAuth\Exceptions\IdentityAlreadyLinkedException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\LastIdentityUnlinkDeniedException;
use Ronu\LaravelFederatedAuth\Exceptions\PackageDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\UserProvisioningNotConfiguredException;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;
use Throwable;

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
        private readonly ?AuthenticationTransactionInterface $transactions = null,
        // Nullable and last so that hosts (and tests) constructing the broker
        // positionally keep working; a null reporter simply captures nothing.
        private readonly ?ErrorReporterInterface $errors = null,
    ) {}

    public function redirectUrl(string $provider, AuthContext $context): string
    {
        try {
            return $this->buildRedirectUrl($provider, $context);
        } catch (Throwable $exception) {
            // A redirect that never gets built is invisible otherwise: no
            // callback arrives, so no login failure is ever recorded, and the
            // user just sees a dead button. This is the leg worth capturing.
            $this->captureError(FederatedAuthError::OPERATION_REDIRECT, $context, $exception);

            throw $exception;
        }
    }

    private function buildRedirectUrl(string $provider, AuthContext $context): string
    {
        $this->ensurePackageEnabled();
        ProviderConfig::get($provider, $context);

        $url = $this->providers->adapterFor($provider)->redirectUrl($context);

        // The adapter mints the one-time state internally, so the broker never
        // sees it directly — but it is a query parameter of the URL just built,
        // which is the same value by construction. Reading it back is what lets
        // the outbound leg carry the correlation digest that joins it to the
        // callback, without widening IdentityProviderAdapterInterface.
        Event::dispatch(new ExternalRedirectIssued(
            $provider,
            $context,
            OAuthSecurity::stateDigest($this->stateFromAuthorizationUrl($url)),
        ));

        return $url;
    }

    public function loginFromCallback(string $provider, AuthContext $context): AuthResult
    {
        $this->ensurePackageEnabled();

        try {
            $context = $this->contextForCallback($provider, $context);
            $identity = $this->providers->adapterFor($provider)->userFromCallback($context);

            return $this->authenticateIdentity($identity, $context);
        } catch (Throwable $exception) {
            $this->reportLoginFailure(
                $context,
                $exception,
                $identity ?? null,
                FederatedAuthError::OPERATION_LOGIN_CALLBACK,
            );

            throw $exception;
        }
    }

    public function loginFromToken(string $provider, string $token, AuthContext $context): AuthResult
    {
        $this->ensurePackageEnabled();

        try {
            ProviderConfig::get($provider, $context);
            $identity = $this->providers->adapterFor($provider)->userFromToken($token, $context);

            return $this->authenticateIdentity($identity, $context);
        } catch (Throwable $exception) {
            $this->reportLoginFailure(
                $context,
                $exception,
                $identity ?? null,
                FederatedAuthError::OPERATION_LOGIN_TOKEN,
            );

            throw $exception;
        }
    }

    public function linkIdentity(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): AuthResult
    {
        $operation = function () use ($user, $identity, $context): AuthResult {
            $this->ensurePackageEnabled();
            $cfg = ProviderConfig::get($identity->provider, $context);
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
        };

        try {
            return $this->runTransaction($operation);
        } catch (Throwable $exception) {
            $this->captureError(FederatedAuthError::OPERATION_LINK, $context, $exception, $identity, $user);

            throw $exception;
        }
    }

    public function unlink(Authenticatable $user, string $provider, AuthContext $context): void
    {
        try {
            $this->unlinkIdentity($user, $provider, $context);
        } catch (Throwable $exception) {
            $this->captureError(FederatedAuthError::OPERATION_UNLINK, $context, $exception, null, $user);

            throw $exception;
        }
    }

    private function unlinkIdentity(Authenticatable $user, string $provider, AuthContext $context): void
    {
        $this->ensurePackageEnabled();
        ProviderConfig::get($provider, $context);
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
        return $this->runTransaction(
            fn (): AuthResult => $this->authenticateIdentityAtomically($identity, $context)
        );
    }

    private function authenticateIdentityAtomically(ExternalIdentity $identity, AuthContext $context): AuthResult
    {
        $cfg = ProviderConfig::get($identity->provider, $context);
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

        if ($this->emailLinkingAllowed($cfg, $context)) {
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

            $this->ensureCanAutoProvision($context);
            $user = $this->provisioner->provision($identity, $context);
            $wasProvisioned = true;
            Event::dispatch(new ExternalUserProvisioned($user, $identity, $context));
        }

        $this->statusChecker->ensureCanLogin($user, $context);
        $this->links->create($user->getAuthIdentifier(), $identity, $context);
        $this->roleMapper->sync($user, $identity, $context);

        return $this->success($user, $identity, $context, $wasProvisioned, true);
    }

    /**
     * Emit the failure signal for the two public authentication entry points.
     *
     * Deliberately not emitted from authenticateIdentity(): loginFromCallback()
     * and loginFromToken() delegate to it, so reporting in both places would
     * dispatch the same failure twice. Reporting at the entry points also covers
     * everything that can fail *before* an identity exists — state consumption,
     * provider token verification — which is where redirect flows most often die.
     *
     * A listener must never break authentication, so a broken listener is
     * swallowed: the original exception is the one that matters and is rethrown
     * by the caller regardless.
     */
    private function reportLoginFailure(
        AuthContext $context,
        Throwable $exception,
        ?ExternalIdentity $identity = null,
        string $operation = FederatedAuthError::OPERATION_LOGIN_CALLBACK,
    ): void {
        try {
            Event::dispatch(new ExternalLoginFailed(null, $identity, $context, $exception));
        } catch (Throwable) {
            // Intentionally ignored.
        }

        $this->captureError($operation, $context, $exception, $identity);
    }

    /**
     * Hand the failure to the configured error reporter.
     *
     * Separate from the event above because the two answer different questions.
     * The event is a domain signal about *login*, and firing it for a failed
     * unlink would be a lie to every existing listener. This path is about
     * durable capture and covers every operation the broker exposes.
     *
     * The reporter is guarded here as well as internally: a host binding its own
     * ErrorReporterInterface must not be able to replace an authentication error
     * with a database error from the code that was trying to record it.
     */
    private function captureError(
        string $operation,
        AuthContext $context,
        Throwable $exception,
        ?ExternalIdentity $identity = null,
        ?Authenticatable $user = null,
    ): void {
        if (! $this->errors) {
            return;
        }

        try {
            $this->errors->report(new FederatedAuthError(
                operation: $operation,
                exception: $exception,
                context: $context,
                identity: $identity,
                user: $user,
            ));
        } catch (Throwable) {
            // Intentionally ignored — see the docblock.
        }
    }

    /**
     * Extract the `state` query parameter from a freshly built authorization URL.
     */
    private function stateFromAuthorizationUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $state = $parameters['state'] ?? null;

        return is_string($state) && $state !== '' ? $state : null;
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
    }

    private function emailLinkingAllowed(array $cfg, AuthContext $context): bool
    {
        if (($cfg['allow_email_linking'] ?? false) !== true) {
            return false;
        }

        $allowedUserTypes = $cfg['email_linking_allowed_user_types'] ?? [];

        if ($allowedUserTypes === []) {
            return true;
        }

        if (! is_array($allowedUserTypes)) {
            return false;
        }

        return $context->userType !== null
            && in_array($context->userType, $allowedUserTypes, true);
    }

    private function ensureCanAutoProvision(AuthContext $context): void
    {
        if (! config('federated-auth.security.prevent_admin_auto_provision', true)) {
            return;
        }

        $adminUserTypes = config('federated-auth.security.admin_user_types', []);

        if ($context->userType && in_array($context->userType, $adminUserTypes, true)) {
            throw new ProviderDisabledException('Admin users cannot be auto-provisioned through federated auth.');
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

    private function runTransaction(callable $operation): mixed
    {
        return $this->transactions
            ? $this->transactions->run($operation)
            : $operation();
    }

    private function ensurePackageEnabled(): void
    {
        if (! config('federated-auth.enabled', true)) {
            throw new PackageDisabledException('Federated authentication is globally disabled.');
        }
    }
}
