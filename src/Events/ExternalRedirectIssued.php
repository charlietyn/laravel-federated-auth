<?php

namespace Ronu\LaravelFederatedAuth\Events;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;

/**
 * Dispatched when the package has built a provider authorization URL and is
 * about to hand the browser over to the identity provider.
 *
 * This is the only signal available for the outbound leg of a redirect flow.
 * Without it, an attempt that leaves for the provider and never comes back —
 * the user closes the tab, the provider errors, the callback URI is misregistered
 * — leaves no trace at all on the application side, which is precisely the case
 * that is hardest to diagnose.
 *
 * `$stateDigest` is a truncated SHA-256 of the one-time state, never the state
 * itself: listeners are expected to log it, and the raw value is replayable
 * until consumed. The matching digest appears on ExternalLoginSucceeded and
 * ExternalLoginFailed, so the two legs can be joined.
 */
class ExternalRedirectIssued
{
    public function __construct(
        public readonly string $provider,
        public readonly AuthContext $context,
        public readonly ?string $stateDigest = null,
    ) {}
}
