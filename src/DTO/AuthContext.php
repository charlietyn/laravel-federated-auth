<?php

namespace Ronu\LaravelFederatedAuth\DTO;

use Illuminate\Http\Request;

final class AuthContext
{
    public function __construct(
        public readonly string $provider,
        public readonly ?Request $request = null,
        public readonly ?string $guard = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $userType = null,
        public readonly ?string $channel = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
        public readonly array $metadata = [],
        public readonly ?OAuthAuthorizationState $authorizationState = null,
    ) {}

    public static function fromRequest(string $provider, Request $request): self
    {
        return new self(
            provider: $provider,
            request: $request,
            guard: $request->input('guard'),
            tenantId: $request->input('tenant_id') ?? $request->header('X-Tenant-Id'),
            userType: $request->input('user_type'),
            channel: $request->input('channel') ?? $request->header('X-Channel'),
            redirectUri: $request->input('redirect_uri'),
            state: $request->input('state') ?? $request->query('state'),
            metadata: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );
    }

    /**
     * Return a callback context enriched with the original redirect transaction state.
     *
     * Provider callbacks usually only carry `code` and `state`; tenant/user type/channel/guard
     * must therefore be restored from the one-time state created before redirecting.
     */
    public function withAuthorizationState(OAuthAuthorizationState $state): self
    {
        return new self(
            provider: $this->provider,
            request: $this->request,
            guard: $state->guard ?? $this->guard,
            tenantId: $state->tenantId ?? $this->tenantId,
            userType: $state->userType ?? $this->userType,
            channel: $state->channel ?? $this->channel,
            redirectUri: $state->redirectUri ?? $this->redirectUri,
            state: $state->state,
            metadata: array_merge($this->metadata, $state->metadata, [
                'oauth_state_restored' => true,
            ]),
            authorizationState: $state,
        );
    }
}
