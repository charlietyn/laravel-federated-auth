<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Support\ProviderConfig;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class ChannelProviderConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'clients' => [
                'site' => [
                    'client_id' => 'google-site-client',
                    'client_secret' => 'site-secret',
                    'redirect_uri' => 'https://site.example.test/callback',
                ],
                'mobile' => [
                    'client_id' => 'google-mobile-client',
                    'redirect_uri' => 'com.example.app:/oauth/callback',
                ],
                'admin' => [
                    'enabled' => false,
                    'client_id' => 'google-admin-client',
                ],
            ],
        ]);
    }

    public function test_resolves_the_client_for_the_trusted_channel(): void
    {
        $config = ProviderConfig::get(
            'google',
            new AuthContext(provider: 'google', channel: 'site'),
        );

        $this->assertSame('google-site-client', $config['client_id']);
        $this->assertSame('site', $config['resolved_channel']);
    }

    public function test_rejects_a_missing_or_unknown_channel(): void
    {
        $this->expectException(ProviderDisabledException::class);

        ProviderConfig::get('google', new AuthContext(provider: 'google'));
    }

    public function test_rejects_a_disabled_channel_client(): void
    {
        $this->expectException(ProviderDisabledException::class);

        ProviderConfig::get(
            'google',
            new AuthContext(provider: 'google', channel: 'admin'),
        );
    }

    public function test_frontend_cannot_select_a_client_outside_the_context(): void
    {
        $config = ProviderConfig::get(
            'google',
            new AuthContext(
                provider: 'google',
                channel: 'mobile',
                metadata: ['requested_client_id' => 'google-admin-client'],
            ),
        );

        $this->assertSame('google-mobile-client', $config['client_id']);
        $this->assertNotSame(
            $config['client_id'],
            $config['requested_client_id'] ?? null,
        );
    }
}
