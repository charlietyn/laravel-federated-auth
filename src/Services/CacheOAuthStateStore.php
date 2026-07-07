<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

class CacheOAuthStateStore implements OAuthStateStoreInterface
{
    public function create(string $provider, AuthContext $context, array $attributes = []): OAuthAuthorizationState
    {
        $ttl = max(60, (int) config('federated-auth.security.oauth_state.ttl_seconds', 300));
        $bindUserAgent = (bool) config('federated-auth.security.oauth_state.bind_user_agent', true);
        $bindIp = (bool) config('federated-auth.security.oauth_state.bind_ip', false);
        $pkceEnabled = (bool) config('federated-auth.security.pkce.enabled', true);
        $nonceEnabled = (bool) config('federated-auth.security.oidc.nonce_enabled', true);
        $request = $context->request;
        $fallbackRedirectUri = $attributes['redirect_uri'] ?? ProviderConfig::value($provider, 'redirect_uri');
        $redirectUri = OAuthSecurity::validateRedirectUri($context->redirectUri, $fallbackRedirectUri);
        $codeVerifier = $pkceEnabled ? ($attributes['code_verifier'] ?? OAuthSecurity::codeVerifier()) : null;
        $state = new OAuthAuthorizationState(
            state: $attributes['state'] ?? OAuthSecurity::randomToken(32),
            provider: $provider,
            redirectUri: $redirectUri,
            tenantId: $context->tenantId,
            userType: $context->userType,
            channel: $context->channel,
            guard: $context->guard,
            nonce: $nonceEnabled ? ($attributes['nonce'] ?? OAuthSecurity::randomToken(32)) : null,
            codeVerifier: $codeVerifier,
            codeChallenge: $codeVerifier ? OAuthSecurity::codeChallenge($codeVerifier) : null,
            codeChallengeMethod: $codeVerifier ? 'S256' : null,
            fingerprint: $request ? OAuthSecurity::fingerprint($request, $bindUserAgent, $bindIp) : [],
            metadata: array_merge($context->metadata, $attributes['metadata'] ?? []),
            expiresAt: time() + $ttl,
        );

        Cache::put($this->key($state->state), $state->toArray(), now()->addSeconds($ttl));

        return $state;
    }

    public function consume(string $provider, string $state, Request $request): OAuthAuthorizationState
    {
        $payload = Cache::pull($this->key($state));

        if (! is_array($payload)) {
            throw new InvalidOAuthStateException('OAuth state is missing, expired or already consumed.');
        }

        $stored = OAuthAuthorizationState::fromArray($payload);

        if (! hash_equals($stored->provider, $provider)) {
            throw new InvalidOAuthStateException('OAuth state provider mismatch.');
        }

        if ($stored->isExpired()) {
            throw new InvalidOAuthStateException('OAuth state expired.');
        }

        $bindUserAgent = (bool) config('federated-auth.security.oauth_state.bind_user_agent', true);
        $bindIp = (bool) config('federated-auth.security.oauth_state.bind_ip', false);
        $currentFingerprint = OAuthSecurity::fingerprint($request, $bindUserAgent, $bindIp);

        foreach ($stored->fingerprint as $key => $expected) {
            $actual = $currentFingerprint[$key] ?? '';

            if (! is_string($expected) || ! hash_equals($expected, (string) $actual)) {
                throw new InvalidOAuthStateException('OAuth state fingerprint mismatch.');
            }
        }

        return $stored;
    }

    private function key(string $state): string
    {
        return rtrim((string) config('federated-auth.security.oauth_state.cache_prefix', 'federated-auth:oauth-state:'), ':').':'.$state;
    }
}
