<?php

namespace Ronu\LaravelFederatedAuth\DTO;

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
