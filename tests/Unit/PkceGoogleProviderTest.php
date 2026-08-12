<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderTokenExpiredException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderTokenNotYetValidException;
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

    public function test_temporal_clock_skew_failure_has_a_specific_exception_and_restores_global_leeway(): void
    {
        config()->set('federated-auth.security.oidc.clock_skew_seconds', 60);
        JWT::$leeway = 7;
        $provider = (new ExposedPkceGoogleProvider(
            Request::create('/callback', 'GET'),
            'client-id',
            'secret',
            'https://app.example.test/callback',
        ))->withVerificationFailure(new BeforeValidException(
            'Cannot handle token with iat prior to 2026-08-12T22:00:15+00:00'
        ));

        try {
            $provider->userFromVerifiedIdToken('header.payload.signature');
            $this->fail('The future token must be rejected.');
        } catch (ProviderTokenNotYetValidException $exception) {
            $this->assertInstanceOf(BeforeValidException::class, $exception->getPrevious());
            $this->assertSame(60, $provider->observedLeeway);
        } finally {
            $this->assertSame(7, JWT::$leeway);
            JWT::$leeway = 0;
        }
    }

    public function test_expired_token_has_a_specific_exception(): void
    {
        $provider = (new ExposedPkceGoogleProvider(
            Request::create('/callback', 'GET'),
            'client-id',
            'secret',
            'https://app.example.test/callback',
        ))->withVerificationFailure(new ExpiredException('Expired token'));

        $this->expectException(ProviderTokenExpiredException::class);
        $this->expectExceptionMessage('Google ID token has expired.');
        $provider->userFromVerifiedIdToken('header.payload.signature');
    }
}

final class ExposedPkceGoogleProvider extends PkceGoogleProvider
{
    private array $claims = [];

    private ?\Throwable $verificationFailure = null;

    public ?int $observedLeeway = null;

    public function exposedTokenFields(string $code): array
    {
        return $this->getTokenFields($code);
    }

    public function withVerifiedClaims(array $claims): self
    {
        $this->claims = $claims;

        return $this;
    }

    public function withVerificationFailure(\Throwable $exception): self
    {
        $this->verificationFailure = $exception;

        return $this;
    }

    protected function getUserFromJwtToken($idToken)
    {
        $this->observedLeeway = JWT::$leeway;

        if ($this->verificationFailure) {
            throw $this->verificationFailure;
        }

        return $this->claims;
    }
}
