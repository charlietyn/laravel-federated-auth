<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Providers\GenericOidcProviderAdapter;
use Ronu\LaravelFederatedAuth\Providers\SocialiteProviderAdapter;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class SocialiteAuthorizationParametersTest extends TestCase
{
    public function test_configured_parameters_are_forwarded_without_allowing_oauth_transaction_overrides(): void
    {
        config()->set('federated-auth.security.oauth_state.enabled', true);
        config()->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'scopes' => ['openid', 'email'],
            'stateless' => true,
            'supports_nonce' => true,
            'authorization_params' => [
                'prompt' => 'select_account',
                'access_type' => 'offline',
                'include_granted_scopes' => true,
                'state' => 'attacker-state',
                'nonce' => 'attacker-nonce',
                'code_challenge' => 'attacker-challenge',
                'code_challenge_method' => 'plain',
                'client_id' => 'attacker-client',
                'client_secret' => 'attacker-secret',
                'redirect_uri' => 'https://attacker.example/callback',
                'response_type' => 'token',
                'scope' => 'everything',
                'nested' => ['not-supported'],
                'bad parameter' => 'not-supported',
                'empty' => '',
            ],
        ]);

        $driver = new RecordingSocialiteDriver;
        $adapter = new RecordingSocialiteAdapter($driver, new FixedOAuthStateStore);

        $url = $adapter->redirectUrl(new AuthContext(
            provider: 'google',
            request: Request::create('/login/google'),
        ));

        $this->assertSame('https://provider.example/authorize', $url);
        $this->assertSame(['openid', 'email'], $driver->requestedScopes);
        $this->assertTrue($driver->stateless);
        $this->assertSame('select_account', $driver->parameters['prompt']);
        $this->assertSame('offline', $driver->parameters['access_type']);
        $this->assertTrue($driver->parameters['include_granted_scopes']);
        $this->assertSame('package-state', $driver->parameters['state']);
        $this->assertSame('package-nonce', $driver->parameters['nonce']);
        $this->assertSame('package-challenge', $driver->parameters['code_challenge']);
        $this->assertSame('S256', $driver->parameters['code_challenge_method']);

        foreach ([
            'client_id',
            'client_secret',
            'redirect_uri',
            'response_type',
            'scope',
            'nested',
            'bad parameter',
            'empty',
        ] as $parameter) {
            $this->assertArrayNotHasKey($parameter, $driver->parameters);
        }
    }

    public function test_authorization_parameters_remain_opt_in(): void
    {
        config()->set('federated-auth.security.oauth_state.enabled', false);
        config()->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'stateless' => true,
            'authorization_params' => [
                'prompt' => null,
            ],
        ]);

        $driver = new RecordingSocialiteDriver;
        $adapter = new RecordingSocialiteAdapter($driver, new FixedOAuthStateStore);

        $adapter->redirectUrl(new AuthContext(
            provider: 'google',
            request: Request::create('/login/google'),
        ));

        $this->assertSame([], $driver->parameters);
        $this->assertSame(0, $driver->withCalls);
    }

    public function test_generic_oidc_uses_custom_parameters_but_keeps_core_parameters_package_owned(): void
    {
        config()->set('federated-auth.security.oauth_state.enabled', true);
        config()->set('federated-auth.providers.enterprise', [
            'enabled' => true,
            'driver' => 'oidc',
            'client_id' => 'real-client',
            'redirect_uri' => 'https://app.example/callback',
            'authorization_endpoint' => 'https://identity.example/authorize',
            'scopes' => ['openid', 'email'],
            'supports_nonce' => true,
            'authorization_params' => [
                'prompt' => 'select_account',
                'ui_locales' => 'es',
                'client_id' => 'attacker-client',
                'state' => 'attacker-state',
                'response_type' => 'token',
                'scope' => 'everything',
            ],
        ]);

        $adapter = new GenericOidcProviderAdapter(states: new FixedOAuthStateStore);
        $url = $adapter->redirectUrl(new AuthContext(
            provider: 'enterprise',
            request: Request::create('/login/enterprise'),
        ));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        $this->assertSame('select_account', $parameters['prompt']);
        $this->assertSame('es', $parameters['ui_locales']);
        $this->assertSame('real-client', $parameters['client_id']);
        $this->assertSame('https://app.example/callback', $parameters['redirect_uri']);
        $this->assertSame('code', $parameters['response_type']);
        $this->assertSame('openid email', $parameters['scope']);
        $this->assertSame('package-state', $parameters['state']);
        $this->assertSame('package-nonce', $parameters['nonce']);
        $this->assertSame('package-challenge', $parameters['code_challenge']);
        $this->assertSame('S256', $parameters['code_challenge_method']);
    }
}

final class RecordingSocialiteAdapter extends SocialiteProviderAdapter
{
    public function __construct(
        private readonly RecordingSocialiteDriver $recordingDriver,
        OAuthStateStoreInterface $states,
    ) {
        parent::__construct($states);
    }

    public function name(): string
    {
        return 'google';
    }

    protected function driver(
        string $provider,
        AuthContext $context,
        ?OAuthAuthorizationState $state = null,
    ): mixed {
        return $this->recordingDriver;
    }
}

final class RecordingSocialiteDriver
{
    /** @var array<string, mixed> */
    public array $parameters = [];

    /** @var list<string> */
    public array $requestedScopes = [];

    public bool $stateless = false;

    public int $withCalls = 0;

    public function scopes(array $scopes): self
    {
        $this->requestedScopes = $scopes;

        return $this;
    }

    public function stateless(): self
    {
        $this->stateless = true;

        return $this;
    }

    public function with(array $parameters): self
    {
        $this->withCalls++;
        $this->parameters = $parameters;

        return $this;
    }

    public function redirect(): object
    {
        return new class
        {
            public function getTargetUrl(): string
            {
                return 'https://provider.example/authorize';
            }
        };
    }
}

final class FixedOAuthStateStore implements OAuthStateStoreInterface
{
    public function create(string $provider, AuthContext $context, array $attributes = []): OAuthAuthorizationState
    {
        return new OAuthAuthorizationState(
            state: 'package-state',
            provider: $provider,
            nonce: 'package-nonce',
            codeVerifier: 'package-verifier',
            codeChallenge: 'package-challenge',
            codeChallengeMethod: 'S256',
        );
    }

    public function consume(string $provider, string $state, Request $request): OAuthAuthorizationState
    {
        throw new \LogicException('Not used by this test.');
    }
}
