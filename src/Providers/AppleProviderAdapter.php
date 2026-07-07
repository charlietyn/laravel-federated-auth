<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Firebase\JWT\JWT;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOidcTokenException;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

class AppleProviderAdapter extends GenericOidcProviderAdapter
{
    public function name(): string
    {
        return 'apple';
    }

    public function supports(string $provider): bool
    {
        $config = config("federated-auth.providers.$provider");

        return is_array($config) && ($config['driver'] ?? null) === 'apple';
    }

    public function redirectUrl(AuthContext $context): string
    {
        $this->derive($context->provider);

        return parent::redirectUrl($context);
    }

    public function userFromCallback(AuthContext $context): ExternalIdentity
    {
        $this->derive($context->provider);

        return parent::userFromCallback($context);
    }

    public function userFromToken(string $token, AuthContext $context): ExternalIdentity
    {
        $this->derive($context->provider);

        return parent::userFromToken($token, $context);
    }

    protected function clientSecret(array $config): ?string
    {
        if (! empty($config['client_secret'])) {
            return $config['client_secret'];
        }

        foreach (['team_id', 'key_id', 'client_id'] as $required) {
            if (empty($config[$required])) {
                throw new InvalidOidcTokenException("Apple provider [$required] is required to generate client_secret.");
            }
        }

        $privateKey = $this->resolvePrivateKey($config);
        $issuedAt = time();
        $ttl = min(max((int) ($config['client_secret_ttl_seconds'] ?? 86400), 60), 15777000);

        return JWT::encode([
            'iss' => $config['team_id'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            'aud' => 'https://appleid.apple.com',
            'sub' => $config['client_id'],
        ], $privateKey, 'ES256', $config['key_id']);
    }

    private function derive(string $provider): void
    {
        $config = ProviderConfig::get($provider);

        config()->set("federated-auth.providers.$provider.issuer", $config['issuer'] ?: 'https://appleid.apple.com');
        config()->set("federated-auth.providers.$provider.authorization_endpoint", $config['authorization_endpoint'] ?: 'https://appleid.apple.com/auth/authorize');
        config()->set("federated-auth.providers.$provider.token_endpoint", $config['token_endpoint'] ?: 'https://appleid.apple.com/auth/token');
        config()->set("federated-auth.providers.$provider.jwks_uri", $config['jwks_uri'] ?: 'https://appleid.apple.com/auth/keys');
        config()->set("federated-auth.providers.$provider.userinfo_endpoint", $config['userinfo_endpoint'] ?? null);
    }

    private function resolvePrivateKey(array $config): string
    {
        if (! empty($config['private_key'])) {
            return str_replace('\\n', "\n", $config['private_key']);
        }

        if (! empty($config['private_key_path']) && is_readable($config['private_key_path'])) {
            $key = file_get_contents($config['private_key_path']);

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        throw new InvalidOidcTokenException('Apple private key is missing or unreadable.');
    }
}
