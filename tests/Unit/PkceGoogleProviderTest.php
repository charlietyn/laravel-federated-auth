<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
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
}

final class ExposedPkceGoogleProvider extends PkceGoogleProvider
{
    public function exposedTokenFields(string $code): array
    {
        return $this->getTokenFields($code);
    }
}
