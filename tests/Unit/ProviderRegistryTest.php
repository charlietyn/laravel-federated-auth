<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderNotSupportedException;
use Ronu\LaravelFederatedAuth\Services\IdentityProviderRegistry;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_it_resolves_registered_adapter(): void
    {
        $registry = new IdentityProviderRegistry;
        $adapter = new class implements IdentityProviderAdapterInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function supports(string $provider): bool
            {
                return $provider === 'fake';
            }

            public function redirectUrl(AuthContext $context): string
            {
                return 'https://fake.test';
            }

            public function userFromCallback(AuthContext $context): ExternalIdentity
            {
                return new ExternalIdentity('fake', '1');
            }

            public function userFromToken(string $token, AuthContext $context): ExternalIdentity
            {
                return new ExternalIdentity('fake', '1');
            }
        };

        $registry->register($adapter);

        $this->assertSame($adapter, $registry->adapterFor('fake'));
    }

    public function test_it_throws_when_no_adapter_supports_provider(): void
    {
        $this->expectException(ProviderNotSupportedException::class);

        (new IdentityProviderRegistry)->adapterFor('unknown');
    }
}
