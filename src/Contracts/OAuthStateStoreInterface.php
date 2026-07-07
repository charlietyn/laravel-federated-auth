<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;

interface OAuthStateStoreInterface
{
    /**
     * Create a one-time authorization transaction state for a redirect-based flow.
     */
    public function create(string $provider, AuthContext $context, array $attributes = []): OAuthAuthorizationState;

    /**
     * Consume and validate a one-time authorization transaction state.
     */
    public function consume(string $provider, string $state, Request $request): OAuthAuthorizationState;
}
