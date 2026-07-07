<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Laravel\Socialite\Facades\Socialite;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
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
        $config = ProviderConfig::get($context->provider);
        $driver = $this->driver($context->provider);
        $with = [];

        if (! empty($config['scopes'])) {
            $driver->scopes($config['scopes']);
        }

        if ($this->oauthStateEnabled()) {
            $state = $this->stateStore()->create($context->provider, $context);
            $with['state'] = $state->state;

            if (($config['supports_nonce'] ?? false) && $state->nonce) {
                $with['nonce'] = $state->nonce;
            }

            // Package-managed state is intentionally stateless from Socialite's perspective.
            // The package validates and consumes the state itself on callback.
            $driver->stateless();
        } elseif (($config['stateless'] ?? true) === true) {
            $driver->stateless();
        }

        if ($with !== []) {
            $driver->with($with);
        }

        return $driver->redirect()->getTargetUrl();
    }

    public function userFromCallback(AuthContext $context): ExternalIdentity
    {
        $config = ProviderConfig::get($context->provider);
        $driver = $this->driver($context->provider);

        if ($this->oauthStateEnabled()) {
            if (! $context->authorizationState) {
                $incomingState = $context->request?->query('state') ?? $context->request?->input('state');

                if (! is_string($incomingState) || $incomingState === '') {
                    throw new InvalidOAuthStateException('OAuth callback did not include a state value.');
                }

                $this->stateStore()->consume($context->provider, $incomingState, $context->request ?: request());
            }

            $driver->stateless();
        } elseif (($config['stateless'] ?? true) === true) {
            $driver->stateless();
        }

        return $this->normalize($context->provider, $driver->user());
    }

    public function userFromToken(string $token, AuthContext $context): ExternalIdentity
    {
        return $this->normalize($context->provider, $this->driver($context->provider)->userFromToken($token));
    }

    protected function driver(string $provider): mixed
    {
        $config = ProviderConfig::get($provider);
        $socialiteDriver = $config['socialite_driver'] ?? $provider;

        config()->set("services.$socialiteDriver.client_id", $config['client_id'] ?? null);
        config()->set("services.$socialiteDriver.client_secret", $config['client_secret'] ?? null);
        config()->set("services.$socialiteDriver.redirect", $config['redirect_uri'] ?? null);

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
