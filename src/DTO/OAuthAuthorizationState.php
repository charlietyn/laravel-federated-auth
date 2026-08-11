<?php

namespace Ronu\LaravelFederatedAuth\DTO;

use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;

final class OAuthAuthorizationState
{
    public function __construct(
        public readonly string $state,
        public readonly string $provider,
        public readonly ?string $redirectUri = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $userType = null,
        public readonly ?string $channel = null,
        public readonly ?string $guard = null,
        public readonly ?string $nonce = null,
        public readonly ?string $codeVerifier = null,
        public readonly ?string $codeChallenge = null,
        public readonly ?string $codeChallengeMethod = 'S256',
        public readonly array $fingerprint = [],
        public readonly array $metadata = [],
        public readonly ?int $expiresAt = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && time() > $this->expiresAt;
    }

    /**
     * Assert that this state was minted for the flow that is now consuming it.
     *
     * The store already proves a state is authentic, unexpired, unused and bound
     * to the same browser. It cannot prove it is being spent on the flow it was
     * created for: an application that exposes several OAuth entry points on one
     * provider — say sign-in and an invitation-gated registration — hands out
     * states that are all equally valid at every callback. Without this check a
     * state minted by the permissive flow can be redeemed at the restrictive one.
     *
     * Supported keys: `channel`, `user_type`, `guard`, `tenant_id`, and
     * `metadata` (an array of key/value pairs that must all match). Keys that are
     * absent from $expectations are not checked, so callers assert only what
     * their own flow actually pins down.
     *
     * Comparison is strict. A state carrying no value for an expected key fails:
     * the point is to prove the state *was* minted with it, and treating absence
     * as a pass would let a state created before the expectation existed satisfy
     * it.
     *
     * @param  array<string, mixed>  $expectations
     *
     * @throws InvalidOAuthStateException
     */
    public function assertMatches(array $expectations): void
    {
        $actual = [
            'channel' => $this->channel,
            'user_type' => $this->userType,
            'guard' => $this->guard,
            'tenant_id' => $this->tenantId,
        ];

        foreach ($actual as $key => $value) {
            if (! array_key_exists($key, $expectations)) {
                continue;
            }

            if ($value === null || (string) $value !== (string) $expectations[$key]) {
                throw new InvalidOAuthStateException(
                    "OAuth state was not issued for the expected [$key]."
                );
            }
        }

        foreach ((array) ($expectations['metadata'] ?? []) as $key => $expected) {
            if (! array_key_exists($key, $this->metadata) || $this->metadata[$key] !== $expected) {
                throw new InvalidOAuthStateException(
                    "OAuth state was not issued with the expected metadata [$key]."
                );
            }
        }

        foreach ((array) ($expectations['metadata_absent'] ?? []) as $key) {
            if (array_key_exists($key, $this->metadata)) {
                throw new InvalidOAuthStateException(
                    "OAuth state carries metadata [$key] that this flow forbids."
                );
            }
        }
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'provider' => $this->provider,
            'redirect_uri' => $this->redirectUri,
            'tenant_id' => $this->tenantId,
            'user_type' => $this->userType,
            'channel' => $this->channel,
            'guard' => $this->guard,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'fingerprint' => $this->fingerprint,
            'metadata' => $this->metadata,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            state: (string) $payload['state'],
            provider: (string) $payload['provider'],
            redirectUri: $payload['redirect_uri'] ?? null,
            tenantId: $payload['tenant_id'] ?? null,
            userType: $payload['user_type'] ?? null,
            channel: $payload['channel'] ?? null,
            guard: $payload['guard'] ?? null,
            nonce: $payload['nonce'] ?? null,
            codeVerifier: $payload['code_verifier'] ?? null,
            codeChallenge: $payload['code_challenge'] ?? null,
            codeChallengeMethod: $payload['code_challenge_method'] ?? 'S256',
            fingerprint: is_array($payload['fingerprint'] ?? null) ? $payload['fingerprint'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            expiresAt: isset($payload['expires_at']) ? (int) $payload['expires_at'] : null,
        );
    }
}
