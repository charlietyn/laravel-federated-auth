<?php

namespace Ronu\LaravelFederatedAuth\DTO;

use Illuminate\Contracts\Auth\Authenticatable;

final class AuthResult
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $tokens = [],
        public readonly ?ExternalIdentity $externalIdentity = null,
        public readonly bool $wasProvisioned = false,
        public readonly bool $wasLinked = false,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        $payload = [
            'success' => true,
            'was_provisioned' => $this->wasProvisioned,
            'was_linked' => $this->wasLinked,
        ];

        if ((bool) config('federated-auth.response.include_user', true)) {
            $payload['user'] = $this->serializeUser();
        }

        if ((bool) config('federated-auth.response.include_external_identity', false) && $this->externalIdentity) {
            $payload['external_identity'] = [
                'provider' => $this->externalIdentity->provider,
                'provider_user_id' => $this->externalIdentity->providerUserId,
                'email' => $this->externalIdentity->email,
                'email_verified' => $this->externalIdentity->emailVerified,
                'name' => $this->externalIdentity->name,
                'avatar_url' => $this->externalIdentity->avatarUrl,
            ];
        }

        return array_merge($payload, $this->tokens, ['metadata' => $this->metadata]);
    }

    private function serializeUser(): array
    {
        $fields = config('federated-auth.response.user_fields', ['id', 'name', 'email']);

        if (! is_array($fields) || $fields === []) {
            return ['id' => $this->user->getAuthIdentifier()];
        }

        $result = [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $value = data_get($this->user, $field);

            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        $result['auth_identifier'] = $this->user->getAuthIdentifier();

        return $result;
    }
}
