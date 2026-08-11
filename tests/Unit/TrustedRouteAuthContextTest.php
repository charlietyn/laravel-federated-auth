<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class TrustedRouteAuthContextTest extends TestCase
{
    /**
     * Build a request already bound to a route carrying the given defaults,
     * mirroring what Laravel does once the route is matched.
     */
    private function request(array $defaults, array $query = []): Request
    {
        $request = Request::create('/admin/auth/google/login', 'GET', $query);
        $route = new Route(['GET'], '/admin/auth/google/login', ['uses' => fn () => null]);

        foreach ($defaults as $key => $value) {
            $route->defaults($key, $value);
        }

        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_it_reads_the_channel_from_the_route_default(): void
    {
        $context = AuthContext::fromTrustedRoute('google', $this->request([
            'trusted_channel' => 'admin',
            'trusted_user_type' => 'Administrator',
        ]));

        $this->assertSame('admin', $context->channel);
        $this->assertSame('Administrator', $context->userType);
    }

    /**
     * The whole point: the channel picks the OAuth client, the callback URI and
     * the user type that will be accepted, so a caller must not be able to
     * choose it. fromRequest() would have returned 'site' here.
     */
    public function test_request_input_cannot_override_the_trusted_route_default(): void
    {
        $context = AuthContext::fromTrustedRoute('google', $this->request(
            ['trusted_channel' => 'admin', 'trusted_user_type' => 'Administrator'],
            ['channel' => 'site', 'user_type' => 'Client'],
        ));

        $this->assertSame('admin', $context->channel);
        $this->assertSame('Administrator', $context->userType);
    }

    public function test_a_route_without_defaults_yields_no_channel_rather_than_a_guess(): void
    {
        $context = AuthContext::fromTrustedRoute('google', $this->request(
            [],
            ['channel' => 'admin'],
        ));

        // Null is correct: ProviderConfig is fail-closed and will refuse to
        // resolve a client, which is safer than inferring one from the caller.
        $this->assertNull($context->channel);
        $this->assertNull($context->userType);
    }

    public function test_overrides_supply_server_derived_values(): void
    {
        $context = AuthContext::fromTrustedRoute('google', $this->request([
            'trusted_channel' => 'admin',
        ]), [
            'redirect_uri' => 'https://admin.example.com/api/auth/google/login/callback',
            'metadata' => ['federated_login_only' => true],
        ]);

        $this->assertSame(
            'https://admin.example.com/api/auth/google/login/callback',
            $context->redirectUri,
        );
        $this->assertTrue($context->metadata['federated_login_only']);
    }

    public function test_the_state_still_comes_from_the_provider_callback(): void
    {
        $context = AuthContext::fromTrustedRoute('google', $this->request(
            ['trusted_channel' => 'admin'],
            ['state' => 'provider-state'],
        ));

        // Not a trust decision: the state is validated against server-side
        // storage before anything is done with it.
        $this->assertSame('provider-state', $context->state);
    }
}
