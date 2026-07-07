<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;

class KeycloakProviderAdapter extends GenericOidcProviderAdapter
{
    public function name(): string
    {
        return 'keycloak';
    }

    public function supports(string $provider): bool
    {
        $config = config("federated-auth.providers.$provider");

        return is_array($config) && ($config['driver'] ?? null) === 'keycloak';
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

    private function derive(string $provider): void
    {
        $config = ProviderConfig::get($provider);

        if (! empty($config['base_url']) && ! empty($config['realm'])) {
            $base = rtrim($config['base_url'], '/').'/realms/'.$config['realm'].'/protocol/openid-connect';

            config()->set("federated-auth.providers.$provider.issuer", $config['issuer'] ?: rtrim($config['base_url'], '/').'/realms/'.$config['realm']);
            config()->set("federated-auth.providers.$provider.authorization_endpoint", $config['authorization_endpoint'] ?: $base.'/auth');
            config()->set("federated-auth.providers.$provider.token_endpoint", $config['token_endpoint'] ?: $base.'/token');
            config()->set("federated-auth.providers.$provider.userinfo_endpoint", $config['userinfo_endpoint'] ?: $base.'/userinfo');
            config()->set("federated-auth.providers.$provider.jwks_uri", $config['jwks_uri'] ?: $base.'/certs');
        }
    }
}
