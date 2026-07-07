<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOidcTokenException;
use Ronu\LaravelFederatedAuth\Support\ClaimReader;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

class GenericOidcProviderAdapter implements IdentityProviderAdapterInterface
{
    public function __construct(
        private readonly ?ClientInterface $http = null,
        private readonly ?OAuthStateStoreInterface $states = null,
    ) {}

    public function name(): string
    {
        return 'oidc';
    }

    public function supports(string $provider): bool
    {
        $config = config("federated-auth.providers.$provider");

        return is_array($config) && ($config['driver'] ?? null) === 'oidc';
    }

    public function redirectUrl(AuthContext $context): string
    {
        $config = ProviderConfig::get($context->provider);

        if (empty($config['authorization_endpoint'])) {
            throw new InvalidOidcTokenException('OIDC authorization_endpoint is missing.');
        }

        $authorizationState = $this->oauthStateEnabled()
            ? $this->stateStore()->create($context->provider, $context)
            : null;

        $redirectUri = $authorizationState?->redirectUri
            ?: OAuthSecurity::validateRedirectUri($context->redirectUri, $config['redirect_uri'] ?? null);

        $parameters = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes'] ?? ['openid', 'profile', 'email']),
            'state' => $authorizationState?->state ?: ($context->state ?: OAuthSecurity::randomToken(32)),
        ];

        if (($config['response_mode'] ?? null) !== null) {
            $parameters['response_mode'] = $config['response_mode'];
        }

        if (($config['supports_nonce'] ?? true) && $authorizationState?->nonce) {
            $parameters['nonce'] = $authorizationState->nonce;
        }

        if ($authorizationState?->codeChallenge) {
            $parameters['code_challenge'] = $authorizationState->codeChallenge;
            $parameters['code_challenge_method'] = $authorizationState->codeChallengeMethod ?: 'S256';
        }

        return $config['authorization_endpoint'].'?'.http_build_query($parameters);
    }

    public function userFromCallback(AuthContext $context): ExternalIdentity
    {
        $request = $context->request;
        $code = $request?->query('code') ?? $request?->input('code');

        if (! is_string($code) || $code === '') {
            throw new InvalidOidcTokenException('OIDC callback did not include an authorization code.');
        }

        $authorizationState = $context->authorizationState;

        if ($this->oauthStateEnabled() && ! $authorizationState) {
            $incomingState = $request?->query('state') ?? $request?->input('state');

            if (! is_string($incomingState) || $incomingState === '') {
                throw new InvalidOAuthStateException('OIDC callback did not include a state value.');
            }

            $authorizationState = $this->stateStore()->consume($context->provider, $incomingState, $request ?: request());
        }

        $config = ProviderConfig::get($context->provider);
        $redirectUri = $authorizationState?->redirectUri
            ?: OAuthSecurity::validateRedirectUri($context->redirectUri, $config['redirect_uri'] ?? null);

        $formParams = [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ];

        if ($clientSecret = $this->clientSecret($config)) {
            $formParams['client_secret'] = $clientSecret;
        }

        if ($authorizationState?->codeVerifier) {
            $formParams['code_verifier'] = $authorizationState->codeVerifier;
        }

        $response = $this->client()->post($config['token_endpoint'], ['form_params' => $formParams]);
        $payload = json_decode((string) $response->getBody(), true) ?: [];

        return $this->identityFromTokenPayload($context->provider, $payload, $config, $authorizationState);
    }

    public function userFromToken(string $token, AuthContext $context): ExternalIdentity
    {
        $config = ProviderConfig::get($context->provider);

        if (! empty($config['userinfo_endpoint'])) {
            $response = $this->client()->get($config['userinfo_endpoint'], [
                'headers' => ['Authorization' => 'Bearer '.$token],
            ]);

            return $this->identityFromClaims(
                $context->provider,
                json_decode((string) $response->getBody(), true) ?: [],
                ['access_token' => $token],
                $config,
                null,
            );
        }

        return $this->identityFromTokenPayload($context->provider, ['id_token' => $token], $config, null);
    }

    protected function identityFromTokenPayload(
        string $provider,
        array $payload,
        array $config,
        ?OAuthAuthorizationState $authorizationState = null,
    ): ExternalIdentity {
        $claims = [];

        if (! empty($payload['id_token'])) {
            $claims = $this->decodeIdToken($payload['id_token'], $config, $authorizationState);
        }

        if ($claims === [] && ! empty($payload['access_token']) && ! empty($config['userinfo_endpoint'])) {
            $response = $this->client()->get($config['userinfo_endpoint'], [
                'headers' => ['Authorization' => 'Bearer '.$payload['access_token']],
            ]);
            $claims = json_decode((string) $response->getBody(), true) ?: [];
        }

        return $this->identityFromClaims($provider, $claims, $payload, $config, $authorizationState);
    }

    protected function identityFromClaims(
        string $provider,
        array $claims,
        array $payload,
        array $config,
        ?OAuthAuthorizationState $authorizationState = null,
    ): ExternalIdentity {
        $subject = (string) ($claims['sub'] ?? $claims['id'] ?? '');

        if ($subject === '') {
            throw new InvalidOidcTokenException('OIDC identity does not contain a sub claim.');
        }

        return new ExternalIdentity(
            provider: $provider,
            providerUserId: $subject,
            email: $claims['email'] ?? null,
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: $claims['name'] ?? $claims['preferred_username'] ?? null,
            firstName: $claims['given_name'] ?? null,
            lastName: $claims['family_name'] ?? null,
            avatarUrl: $claims['picture'] ?? null,
            raw: $claims,
            claims: $claims,
            groups: ClaimReader::list($claims, $config['groups_claim'] ?? 'groups'),
            roles: ClaimReader::list($claims, $config['roles_claim'] ?? 'roles'),
            accessToken: $payload['access_token'] ?? null,
            refreshToken: $payload['refresh_token'] ?? null,
            expiresIn: isset($payload['expires_in']) ? (int) $payload['expires_in'] : null,
        );
    }

    protected function decodeIdToken(
        string $idToken,
        array $config,
        ?OAuthAuthorizationState $authorizationState = null,
    ): array {
        if (empty($config['jwks_uri'])) {
            throw new InvalidOidcTokenException('Cannot validate id_token because jwks_uri is missing.');
        }

        $jwks = cache()->remember('federated-auth:jwks:'.md5($config['jwks_uri']), now()->addMinutes(10), function () use ($config) {
            $response = $this->client()->get($config['jwks_uri']);

            return json_decode((string) $response->getBody(), true) ?: [];
        });

        $keys = JWK::parseKeySet($jwks);
        $decoded = (array) JWT::decode($idToken, $keys);
        $claims = json_decode(json_encode($decoded), true) ?: [];

        if (! empty($config['issuer']) && ($claims['iss'] ?? null) !== $config['issuer']) {
            throw new InvalidOidcTokenException('OIDC issuer validation failed.');
        }

        if (! empty($config['client_id'])) {
            $audience = $claims['aud'] ?? null;
            $audiences = is_array($audience) ? $audience : [$audience];

            if (! in_array($config['client_id'], $audiences, true)) {
                throw new InvalidOidcTokenException('OIDC audience validation failed.');
            }

            if (
                (bool) config('federated-auth.security.oidc.require_azp_when_multiple_audiences', true)
                && count(array_filter($audiences)) > 1
                && ($claims['azp'] ?? null) !== $config['client_id']
            ) {
                throw new InvalidOidcTokenException('OIDC authorized party validation failed.');
            }
        }

        if ($authorizationState?->nonce && ($claims['nonce'] ?? null) !== $authorizationState->nonce) {
            throw new InvalidOidcTokenException('OIDC nonce validation failed.');
        }

        return $claims;
    }

    protected function clientSecret(array $config): ?string
    {
        return $config['client_secret'] ?? null;
    }

    protected function client(): ClientInterface
    {
        return $this->http ?: new Client(['timeout' => 10]);
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
