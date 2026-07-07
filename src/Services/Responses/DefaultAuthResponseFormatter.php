<?php

namespace Ronu\LaravelFederatedAuth\Services\Responses;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;

class DefaultAuthResponseFormatter implements AuthResponseFormatterInterface
{
    public function __construct(private readonly PermissionPayloadResolverInterface $permissions) {}

    public function format(AuthResult $result, AuthContext $context): array
    {
        $payload = [
            'success' => true,
            'was_provisioned' => $result->wasProvisioned,
            'was_linked' => $result->wasLinked,
        ];

        if ((bool) config('federated-auth.response.include_user', true)) {
            $payload['user'] = $this->serializeUser($result->user);
        }

        if ((bool) config('federated-auth.response.include_external_identity', false) && $result->externalIdentity) {
            $payload['external_identity'] = [
                'provider' => $result->externalIdentity->provider,
                'provider_user_id' => $result->externalIdentity->providerUserId,
                'email' => $result->externalIdentity->email,
                'email_verified' => $result->externalIdentity->emailVerified,
                'name' => $result->externalIdentity->name,
                'avatar_url' => $result->externalIdentity->avatarUrl,
            ];
        }

        if ((bool) config('federated-auth.response.include_permissions', false)) {
            $permissions = $this->permissions->resolve($result->user, $context);

            if ($permissions !== []) {
                $payload['permissions'] = $permissions;
            }
        }

        return array_merge($payload, $result->tokens, ['metadata' => $result->metadata]);
    }

    protected function serializeUser(Authenticatable $user): array
    {
        $fields = config('federated-auth.response.user_fields', ['id', 'name', 'email']);

        if (! is_array($fields) || $fields === []) {
            return ['auth_identifier' => $user->getAuthIdentifier()];
        }

        $result = [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $value = data_get($user, $field);

            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        $result['auth_identifier'] = $user->getAuthIdentifier();

        return $result;
    }
}
