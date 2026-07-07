<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Providers\GenericOidcProviderAdapter;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class OidcTokenTypeTest extends TestCase
{
    public function test_oidc_user_from_token_decodes_submitted_id_token_even_when_userinfo_endpoint_exists(): void
    {
        config()->set('federated-auth.providers.keycloak', [
            'enabled' => true,
            'driver' => 'oidc',
            'client_id' => 'mobile-client',
            'issuer' => 'https://sso.example.com/realms/demo',
            'userinfo_endpoint' => 'https://sso.example.com/realms/demo/protocol/openid-connect/userinfo',
            'jwks_uri' => 'https://sso.example.com/realms/demo/protocol/openid-connect/certs',
        ]);

        $client = new FakeOidcTokenHttpClient();
        $adapter = new FakeOidcTokenAdapter($client);
        $identity = $adapter->userFromToken(
            $this->unsignedJwt([
                'iss' => 'https://sso.example.com/realms/demo',
                'aud' => 'mobile-client',
                'sub' => 'user-123',
                'email' => 'client@example.com',
                'email_verified' => true,
                'name' => 'Client Example',
            ]),
            new AuthContext(provider: 'keycloak', providerTokenType: 'id_token'),
        );

        $this->assertSame('user-123', $identity->providerUserId);
        $this->assertSame('client@example.com', $identity->email);
        $this->assertSame(0, $client->userinfoCalls);
    }

    public function test_oidc_user_from_token_uses_userinfo_for_submitted_access_token(): void
    {
        config()->set('federated-auth.providers.keycloak', [
            'enabled' => true,
            'driver' => 'oidc',
            'client_id' => 'mobile-client',
            'issuer' => 'https://sso.example.com/realms/demo',
            'userinfo_endpoint' => 'https://sso.example.com/realms/demo/protocol/openid-connect/userinfo',
            'jwks_uri' => 'https://sso.example.com/realms/demo/protocol/openid-connect/certs',
        ]);

        $client = new FakeOidcTokenHttpClient();
        $adapter = new FakeOidcTokenAdapter($client);
        $identity = $adapter->userFromToken(
            'opaque-access-token',
            new AuthContext(provider: 'keycloak', providerTokenType: 'access_token'),
        );

        $this->assertSame('userinfo-user-1', $identity->providerUserId);
        $this->assertSame(1, $client->userinfoCalls);
    }

    private function unsignedJwt(array $claims): string
    {
        return implode('.', [
            $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none'])),
            $this->base64UrlEncode(json_encode($claims)),
            '',
        ]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class FakeOidcTokenAdapter extends GenericOidcProviderAdapter
{
    protected function decodeIdToken(string $idToken, array $config, ?OAuthAuthorizationState $authorizationState = null): array
    {
        [, $payload] = explode('.', $idToken, 3);

        return json_decode($this->decodeBase64Url($payload), true) ?: [];
    }

    private function decodeBase64Url(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}

final class FakeOidcTokenHttpClient extends \GuzzleHttp\Client
{
    public int $userinfoCalls = 0;

    public function get($uri, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $this->userinfoCalls++;

        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'sub' => 'userinfo-user-1',
            'email' => 'userinfo@example.com',
            'email_verified' => true,
        ]));
    }
}
