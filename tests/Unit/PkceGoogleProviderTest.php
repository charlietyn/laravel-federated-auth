<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Providers\PkceGoogleProvider;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class PkceGoogleProviderTest extends TestCase
{
    public function test_restored_code_verifier_is_sent_to_google_token_endpoint(): void
    {
        $provider = (new ExposedPkceGoogleProvider(
            Request::create('/callback', 'GET', ['code' => 'authorization-code']),
            'channel-client-id',
            'server-secret',
            'https://app.example.test/callback',
        ))->withCodeVerifier('server-side-code-verifier');

        $fields = $provider->exposedTokenFields('authorization-code');

        $this->assertSame('channel-client-id', $fields['client_id']);
        $this->assertSame('https://app.example.test/callback', $fields['redirect_uri']);
        $this->assertSame('server-side-code-verifier', $fields['code_verifier']);
    }

    public function test_native_id_token_is_mapped_from_verified_claims(): void
    {
        $provider = (new ExposedPkceGoogleProvider(
            Request::create('/mobile/token', 'POST'),
            'mobile-client-id',
            null,
            null,
        ))->withVerifiedClaims([
            'sub' => 'google-subject-123',
            'iss' => 'https://accounts.google.com',
            'aud' => 'mobile-client-id',
            'email' => 'verified@example.test',
            'email_verified' => true,
            'name' => 'Verified User',
            'given_name' => 'Verified',
            'family_name' => 'User',
        ]);

        $user = $provider->userFromVerifiedIdToken('header.payload.signature');

        $this->assertSame('google-subject-123', $user->getId());
        $this->assertSame('verified@example.test', $user->getEmail());
        $this->assertTrue((bool) $user->getRaw()['email_verified']);
        $this->assertNull($user->token);
    }

    public function test_native_id_token_requires_a_subject(): void
    {
        $provider = (new ExposedPkceGoogleProvider(
            Request::create('/mobile/token', 'POST'),
            'mobile-client-id',
            null,
            null,
        ))->withVerifiedClaims([
            'iss' => 'https://accounts.google.com',
            'aud' => 'mobile-client-id',
        ]);

        $this->expectException(InvalidProviderTokenException::class);
        $provider->userFromVerifiedIdToken('header.payload.signature');
    }
}

final class ExposedPkceGoogleProvider extends PkceGoogleProvider
{
    private array $claims = [];

    public function exposedTokenFields(string $code): array
    {
        return $this->getTokenFields($code);
    }

    public function withVerifiedClaims(array $claims): self
    {
        $this->claims = $claims;

        return $this;
    }

    protected function getUserFromJwtToken($idToken)
    {
        return $this->claims;
    }
}
