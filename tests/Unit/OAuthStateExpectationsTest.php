<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class OAuthStateExpectationsTest extends TestCase
{
    private function state(array $overrides = []): OAuthAuthorizationState
    {
        // array_key_exists, not ??: a test needs to be able to override a value
        // *to null* to cover the "state carries nothing for this key" case.
        $value = static fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        return new OAuthAuthorizationState(
            state: $value('state', 'state-value'),
            provider: 'google',
            tenantId: $value('tenantId', null),
            userType: $value('userType', 'Administrator'),
            channel: $value('channel', 'admin'),
            guard: $value('guard', 'api'),
            metadata: $value('metadata', ['federated_login_only' => true]),
        );
    }

    public function test_it_accepts_a_state_minted_for_the_same_flow(): void
    {
        $this->state()->assertMatches([
            'channel' => 'admin',
            'user_type' => 'Administrator',
            'metadata' => ['federated_login_only' => true],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_ignores_keys_the_caller_does_not_pin_down(): void
    {
        // Only the channel is asserted; user_type/guard/tenant are irrelevant here.
        $this->state(['userType' => 'Client'])->assertMatches(['channel' => 'admin']);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_state_minted_for_another_channel(): void
    {
        $this->expectException(InvalidOAuthStateException::class);

        $this->state(['channel' => 'site'])->assertMatches(['channel' => 'admin']);
    }

    /**
     * The case this exists for: an application exposing both sign-in and an
     * invitation-gated registration on one provider. A registration state must
     * not be redeemable at the sign-in callback, where provisioning is refused
     * on the assumption that no registration was requested.
     */
    public function test_it_rejects_a_state_minted_for_another_flow(): void
    {
        $registrationState = $this->state([
            'metadata' => ['registration_type' => 'register-admin'],
        ]);

        $this->expectException(InvalidOAuthStateException::class);

        $registrationState->assertMatches([
            'metadata' => ['federated_login_only' => true],
        ]);
    }

    public function test_it_rejects_metadata_the_flow_forbids(): void
    {
        $registrationState = $this->state([
            'metadata' => [
                'federated_login_only' => true,
                'registration_type' => 'register-admin',
            ],
        ]);

        $this->expectException(InvalidOAuthStateException::class);

        $registrationState->assertMatches([
            'metadata' => ['federated_login_only' => true],
            'metadata_absent' => ['registration_type'],
        ]);
    }

    /**
     * Absence must fail, not pass. Otherwise a state created before an
     * expectation existed would silently satisfy it.
     */
    public function test_a_missing_value_does_not_satisfy_an_expectation(): void
    {
        $this->expectException(InvalidOAuthStateException::class);

        $this->state(['channel' => null])->assertMatches(['channel' => 'admin']);
    }
}
