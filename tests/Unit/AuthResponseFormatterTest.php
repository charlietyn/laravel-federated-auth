<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericAuthResponseFormatter;
use Ronu\LaravelFederatedAuth\Services\Permissions\NullPermissionPayloadResolver;
use Ronu\LaravelFederatedAuth\Services\Responses\DefaultAuthResponseFormatter;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class AuthResponseFormatterTest extends TestCase
{
    public function test_default_formatter_returns_configured_user_payload_only(): void
    {
        config()->set('federated-auth.response.user_fields', ['id', 'email']);
        config()->set('federated-auth.response.include_permissions', false);

        $formatter = new DefaultAuthResponseFormatter(new NullPermissionPayloadResolver());
        $result = new AuthResult(
            user: new FakeAuthenticatableUser(['id' => 10, 'email' => 'client@example.com', 'password' => 'secret']),
            tokens: ['access_token' => 'token', 'token_type' => 'bearer'],
        );

        $payload = $formatter->format($result, new AuthContext('google'));

        $this->assertTrue($payload['success']);
        $this->assertSame(10, $payload['user']['id']);
        $this->assertSame('client@example.com', $payload['user']['email']);
        $this->assertArrayNotHasKey('password', $payload['user']);
        $this->assertSame('token', $payload['access_token']);
    }

    public function test_rest_generic_formatter_returns_ok_data_meta_shape(): void
    {
        config()->set('federated-auth.response.user_fields', ['id', 'email']);
        config()->set('federated-auth.response.include_permissions', false);

        $formatter = new RestGenericAuthResponseFormatter(new NullPermissionPayloadResolver());
        $result = new AuthResult(
            user: new FakeAuthenticatableUser(['id' => 25, 'email' => 'client@example.com']),
            tokens: ['access_token' => 'token', 'token_type' => 'bearer', 'expires_in' => 60],
            wasProvisioned: false,
            wasLinked: true,
        );

        $payload = $formatter->format($result, new AuthContext(provider: 'apple', channel: 'mobile'));

        $this->assertTrue($payload['ok']);
        $this->assertSame(25, $payload['data']['user']['id']);
        $this->assertSame('token', $payload['data']['auth']['access_token']);
        $this->assertSame('apple', $payload['data']['federated']['provider']);
        $this->assertTrue($payload['data']['federated']['was_linked']);
        $this->assertSame('mobile', $payload['meta']['channel']);
    }
}

final class FakeAuthenticatableUser implements Authenticatable
{
    public function __construct(private readonly array $attributes) {}

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return $this->attributes['password'] ?? null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value) {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
