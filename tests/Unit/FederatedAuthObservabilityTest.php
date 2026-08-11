<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginFailed;
use Ronu\LaravelFederatedAuth\Events\ExternalRedirectIssued;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;
use Ronu\LaravelFederatedAuth\Services\Logging\FederatedAuthLogSubscriber;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class FederatedAuthObservabilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('federated-auth.enabled', true);
        $app['config']->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://app.example.com/callback',
        ]);
    }

    private function swapAdapter(IdentityProviderAdapterInterface $adapter): void
    {
        $registry = Mockery::mock(IdentityProviderRegistryInterface::class);
        $registry->shouldReceive('adapterFor')->andReturn($adapter);
        $this->app->instance(IdentityProviderRegistryInterface::class, $registry);
    }

    private function context(): AuthContext
    {
        return new AuthContext(
            provider: 'google',
            request: Request::create('/auth/google/redirect'),
            channel: 'admin',
        );
    }

    public function test_the_redirect_leg_emits_an_event_with_a_correlation_digest(): void
    {
        Event::fake([ExternalRedirectIssued::class]);

        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('redirectUrl')->andReturn(
            'https://accounts.google.com/o/oauth2/auth?client_id=x&state=the-real-state'
        );
        $this->swapAdapter($adapter);

        $this->app->make(FederatedAuthBroker::class)->redirectUrl('google', $this->context());

        Event::assertDispatched(ExternalRedirectIssued::class, function (ExternalRedirectIssued $event): bool {
            // The digest must correlate with the callback leg but must not be
            // the state itself: a leaked state is replayable until consumed.
            $this->assertSame(OAuthSecurity::stateDigest('the-real-state'), $event->stateDigest);
            $this->assertNotSame('the-real-state', $event->stateDigest);
            $this->assertSame('admin', $event->context->channel);

            return true;
        });
    }

    /**
     * ExternalLoginFailed shipped with the package but was never dispatched by
     * any code path, so a failed authentication produced no signal at all.
     */
    public function test_a_failed_authentication_emits_the_failure_event(): void
    {
        Event::fake([ExternalLoginFailed::class]);

        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('userFromToken')
            ->andThrow(new InvalidProviderTokenException('bad token'));
        $this->swapAdapter($adapter);

        try {
            $this->app->make(FederatedAuthBroker::class)
                ->loginFromToken('google', 'forged-token', $this->context());
            $this->fail('The provider exception must still reach the caller.');
        } catch (InvalidProviderTokenException) {
            // Expected: observability must not swallow the failure.
        }

        Event::assertDispatched(ExternalLoginFailed::class, function (ExternalLoginFailed $event): bool {
            $this->assertInstanceOf(InvalidProviderTokenException::class, $event->exception);
            $this->assertSame('admin', $event->context->channel);

            return true;
        });
    }

    public function test_the_log_subscriber_is_not_registered_unless_enabled(): void
    {
        // Default must stay off: upgrading cannot start writing to a host's log.
        $this->assertFalse(config('federated-auth.logging.enabled'));
        $this->assertEmpty(Event::getListeners(ExternalRedirectIssued::class));
    }

    public function test_the_subscriber_never_records_the_raw_state(): void
    {
        $context = new AuthContext(
            provider: 'google',
            request: Request::create('/auth/google/callback'),
            channel: 'admin',
            state: 'the-real-state',
        );

        $captured = [];
        $this->app['log']->listen(function ($message) use (&$captured): void {
            $captured[] = $message->context;
        });

        (new FederatedAuthLogSubscriber)->handleRedirectIssued(
            new ExternalRedirectIssued('google', $context, OAuthSecurity::stateDigest('the-real-state')),
        );

        $this->assertCount(1, $captured);
        $serialized = json_encode($captured[0]);
        $this->assertStringNotContainsString('the-real-state', $serialized);
        $this->assertStringContainsString(OAuthSecurity::stateDigest('the-real-state'), $serialized);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
