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
        public readonly ?string $providerTokenType = null,
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
            providerTokenType: self::providerTokenTypeFromRequest($request),
        );
    }

    /**
     * Build a context whose channel, user type, tenant and guard come from route
     * defaults instead of from the request.
     *
     * fromRequest() reads those four values out of the body, the query string or
     * a header. For a single-tenant, single-channel application that is fine.
     * For an application where the channel selects the OAuth client, the callback
     * URI and the user type that will be accepted, it is not: the caller would be
     * choosing the terms of their own authentication. A route default cannot be
     * overridden by a request field, which is what makes the crossing impossible
     * rather than merely filtered.
     *
     * Register routes with the defaults named in `federated-auth.trusted_route.keys`:
     *
     *     Route::get('/auth/google/login', [SignInController::class, 'redirect'])
     *         ->defaults('trusted_channel', 'admin');
     *
     * $overrides supplies values the application derives server-side (a callback
     * URI resolved from its own policy, trusted metadata). It is NOT a way to
     * pass request input back in — anything placed there must originate in the
     * application, never in the caller.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromTrustedRoute(
        string $provider,
        Request $request,
        array $overrides = [],
    ): self {
        $keys = (array) config('federated-auth.trusted_route.keys', []);
        $route = static function (string $name) use ($request, $keys): ?string {
            $key = $keys[$name] ?? null;

            if (! is_string($key) || $key === '') {
                return null;
            }

            $value = $request->route($key);

            return is_string($value) && $value !== '' ? $value : null;
        };

        return new self(
            provider: $provider,
            request: $request,
            guard: $overrides['guard'] ?? $route('guard'),
            tenantId: $overrides['tenant_id'] ?? $route('tenant_id'),
            userType: $overrides['user_type'] ?? $route('user_type'),
            channel: $overrides['channel'] ?? $route('channel'),
            redirectUri: $overrides['redirect_uri'] ?? null,
            // The state is the one value that legitimately arrives from the
            // provider: it is validated against server-side storage, not trusted.
            state: $overrides['state'] ?? ($request->input('state') ?? $request->query('state')),
            metadata: $overrides['metadata'] ?? [],
            providerTokenType: $overrides['provider_token_type'] ?? self::providerTokenTypeFromRequest($request),
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
            providerTokenType: $this->providerTokenType,
        );
    }

    public function withProviderTokenType(?string $providerTokenType): self
    {
        return new self(
            provider: $this->provider,
            request: $this->request,
            guard: $this->guard,
            tenantId: $this->tenantId,
            userType: $this->userType,
            channel: $this->channel,
            redirectUri: $this->redirectUri,
            state: $this->state,
            metadata: $this->metadata,
            authorizationState: $this->authorizationState,
            providerTokenType: $providerTokenType,
        );
    }

    private static function providerTokenTypeFromRequest(Request $request): ?string
    {
        if ($request->filled('id_token')) {
            return 'id_token';
        }

        if ($request->filled('access_token')) {
            return 'access_token';
        }

        return null;
    }
}
