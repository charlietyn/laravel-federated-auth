<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Laravel\Socialite\Facades\Socialite;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

abstract class SocialiteProviderAdapter implements IdentityProviderAdapterInterface
{
    public function __construct(private readonly ?OAuthStateStoreInterface $states = null) {}

    abstract public function name(): string;

    public function supports(string $provider): bool
    {
        $config = config("federated-auth.providers.$provider");

        return is_array($config)
            && ($config['driver'] ?? null) === 'socialite'
            && ($config['socialite_driver'] ?? $provider) === $this->name();
    }

    public function redirectUrl(AuthContext $context): string
    {
        $config = ProviderConfig::get($context->provider, $context);
        $with = [];
        $state = null;

        if ($this->oauthStateEnabled()) {
            $state = $this->stateStore()->create($context->provider, $context);
            $with['state'] = $state->state;

            if (($config['supports_nonce'] ?? false) && $state->nonce) {
                $with['nonce'] = $state->nonce;
            }

            if ($state->codeChallenge) {
                $with['code_challenge'] = $state->codeChallenge;
                $with['code_challenge_method'] = $state->codeChallengeMethod ?: 'S256';
            }
        }

        $driver = $this->driver($context->provider, $context, $state);

        if (! empty($config['scopes'])) {
            $driver->scopes($config['scopes']);
        }

        // The package owns state and PKCE server-side. Socialite must therefore
        // remain stateless and must not create a second session-bound state.
        if ($this->oauthStateEnabled() || ($config['stateless'] ?? true) === true) {
            $driver->stateless();
        }

        if ($with !== []) {
            $driver->with($with);
        }

        return $driver->redirect()->getTargetUrl();
    }

    public function userFromCallback(AuthContext $context): ExternalIdentity
    {
        $config = ProviderConfig::get($context->provider, $context);

        if ($this->oauthStateEnabled() && ! $context->authorizationState) {
            $incomingState = $context->request?->query('state') ?? $context->request?->input('state');

            if (! is_string($incomingState) || $incomingState === '') {
                throw new InvalidOAuthStateException('OAuth callback did not include a state value.');
            }

            // Normally the broker consumes state first. This fallback keeps the
            // adapter safe when called directly by an integration.
            $consumed = $this->stateStore()->consume(
                $context->provider,
                $incomingState,
                $context->request ?: request(),
            );
            $context = $context->withAuthorizationState($consumed);
            $config = ProviderConfig::get($context->provider, $context);
        }

        $driver = $this->driver(
            $context->provider,
            $context,
            $context->authorizationState,
        );

        if ($this->oauthStateEnabled() || ($config['stateless'] ?? true) === true) {
            $driver->stateless();
        }

        if ($driver instanceof PkceGoogleProvider) {
            $user = $driver->userWithVerifiedIdToken(
                $context->authorizationState?->nonce,
            );
        } else {
            $user = $driver->user();
        }

        return $this->normalize($context->provider, $user);
    }

    public function userFromToken(string $token, AuthContext $context): ExternalIdentity
    {
        $driver = $this->driver($context->provider, $context, $context->authorizationState);
        $user = $driver->userFromToken($token);
        $identity = $this->normalize($context->provider, $user);

        if (
            $context->provider === 'google'
            && $context->providerTokenType === 'id_token'
            && $context->authorizationState?->nonce
        ) {
            $actualNonce = (string) ($identity->claims['nonce'] ?? '');

            if ($actualNonce === '' || ! hash_equals($context->authorizationState->nonce, $actualNonce)) {
                throw new InvalidProviderTokenException('Google ID token nonce mismatch.');
            }
        }

        return $identity;
    }

    protected function driver(
        string $provider,
        AuthContext $context,
        ?OAuthAuthorizationState $state = null,
    ): mixed {
        $config = ProviderConfig::get($provider, $context);
        $socialiteDriver = $config['socialite_driver'] ?? $provider;

        if ($socialiteDriver === 'google') {
            return (new PkceGoogleProvider(
                $context->request ?: request(),
                $config['client_id'] ?? null,
                $config['client_secret'] ?? null,
                $state?->redirectUri ?: ($config['redirect_uri'] ?? null),
            ))->withCodeVerifier($state?->codeVerifier);
        }

        config()->set("services.$socialiteDriver.client_id", $config['client_id'] ?? null);
        config()->set("services.$socialiteDriver.client_secret", $config['client_secret'] ?? null);
        config()->set("services.$socialiteDriver.redirect", $state?->redirectUri ?: ($config['redirect_uri'] ?? null));

        return Socialite::driver($socialiteDriver);
    }

    protected function normalize(string $provider, mixed $user): ExternalIdentity
    {
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : ($user->user ?? []);
        $email = method_exists($user, 'getEmail') ? $user->getEmail() : ($user->email ?? null);
        $name = method_exists($user, 'getName') ? $user->getName() : ($user->name ?? null);
        $nickname = method_exists($user, 'getNickname') ? $user->getNickname() : null;
        $avatar = method_exists($user, 'getAvatar') ? $user->getAvatar() : ($user->avatar ?? null);
        $emailVerified = (bool) data_get($raw, 'email_verified', false);

        if ($provider === 'facebook' && $email && (bool) ProviderConfig::value('facebook', 'trust_email_as_verified', false)) {
            $emailVerified = true;
        }

        return new ExternalIdentity(
            provider: $provider,
            providerUserId: (string) (method_exists($user, 'getId') ? $user->getId() : ($user->id ?? '')),
            email: $email,
            emailVerified: $emailVerified,
            name: $name ?: $nickname,
            firstName: data_get($raw, 'given_name'),
            lastName: data_get($raw, 'family_name'),
            avatarUrl: $avatar,
            raw: is_array($raw) ? $raw : [],
            claims: is_array($raw) ? $raw : [],
            groups: [],
            roles: [],
            accessToken: $user->token ?? null,
            refreshToken: $user->refreshToken ?? null,
            expiresIn: $user->expiresIn ?? null,
        );
    }

    private function oauthStateEnabled(): bool
    {
        return (bool) config('federated-auth.security.oauth_state.enabled', true);
    }

    private function stateStore(): OAuthStateStoreInterface
    {
        return $this->states ?: app(OAuthStateStoreInterface::class);
    }
}
