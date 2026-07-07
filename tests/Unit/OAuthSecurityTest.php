<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidRedirectUriException;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class OAuthSecurityTest extends TestCase
{
    public function test_it_generates_s256_pkce_challenge(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            OAuthSecurity::codeChallenge($verifier)
        );
    }

    public function test_it_rejects_non_https_redirect_uri(): void
    {
        config()->set('federated-auth.security.redirects.allowed_hosts', 'example.com');
        config()->set('federated-auth.security.redirects.allow_http_localhost', false);

        $this->expectException(InvalidRedirectUriException::class);

        OAuthSecurity::validateRedirectUri('http://example.com/callback', null);
    }

    public function test_it_allows_configured_https_redirect_host(): void
    {
        config()->set('federated-auth.security.redirects.allowed_hosts', 'example.com,api.example.com');

        $this->assertSame(
            'https://api.example.com/auth/callback',
            OAuthSecurity::validateRedirectUri('https://api.example.com/auth/callback', null)
        );
    }

    public function test_it_builds_stable_user_agent_fingerprint(): void
    {
        $request = Request::create('https://example.com/callback', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'FederatedAuthTest/1.0',
        ]);

        $fingerprint = OAuthSecurity::fingerprint($request, true, false);

        $this->assertArrayHasKey('user_agent_hash', $fingerprint);
        $this->assertArrayNotHasKey('ip_hash', $fingerprint);
    }
}
