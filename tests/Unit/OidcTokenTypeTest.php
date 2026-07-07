<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Firebase\JWT\JWT;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
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

        $adapter = new FakeOidcTokenAdapter();
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
        $this->assertSame(0, $adapter->userinfoCalls);
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

        $adapter = new FakeOidcTokenAdapter();
        $identity = $adapter->userFromToken(
            'opaque-access-token',
            new AuthContext(provider: 'keycloak', providerTokenType: 'access_token'),
        );

        $this->assertSame('userinfo-user-1', $identity->providerUserId);
        $this->assertSame(1, $adapter->userinfoCalls);
    }

    private function unsignedJwt(array $claims): string
    {
        return JWT::encode($claims, '', 'none');
    }
}

final class FakeOidcTokenAdapter extends GenericOidcProviderAdapter
{
    public int $userinfoCalls = 0;

    protected function decodeIdToken(string $idToken, array $config, $authorizationState = null): array
    {
        [$header, $payload] = explode('.', $idToken, 3);

        return json_decode($this->decodeBase64Url($payload), true) ?: [];
    }

    protected function client(): \GuzzleHttp\ClientInterface
    {
        return new class($this) implements \GuzzleHttp\ClientInterface {
            public function __construct(private readonly FakeOidcTokenAdapter $adapter) {}

            public function send(\Psr\Http\Message\RequestInterface $request, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new \BadMethodCallException('Not used.');
            }

            public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \BadMethodCallException('Not used.');
            }

            public function request($method, $uri = '', array $options = []): \Psr\Http\Message\ResponseInterface
            {
                $this->adapter->userinfoCalls++;

                return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'sub' => 'userinfo-user-1',
                    'email' => 'userinfo@example.com',
                    'email_verified' => true,
                ]));
            }

            public function requestAsync($method, $uri = '', array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \BadMethodCallException('Not used.');
            }

            public function getConfig($option = null): mixed
            {
                return null;
            }
        };
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
