<?php

namespace Ronu\LaravelFederatedAuth\Integrations\RestGenericClass;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;

class RestGenericAuthResponseFormatter implements AuthResponseFormatterInterface
{
    public function __construct(private readonly PermissionPayloadResolverInterface $permissions) {}

    public function format(AuthResult $result, AuthContext $context): array
    {
        $data = [
            'user' => $this->serializeUser($result->user),
            'auth' => $this->authPayload($result->tokens),
            'federated' => [
                'provider' => $result->externalIdentity?->provider ?: $context->provider,
                'was_provisioned' => $result->wasProvisioned,
                'was_linked' => $result->wasLinked,
            ],
        ];

        if ((bool) config('federated-auth.response.include_external_identity', false) && $result->externalIdentity) {
            $data['external_identity'] = [
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
                $data['permissions'] = $permissions;
            }
        }

        return [
            'ok' => true,
            'data' => $data,
            'meta' => array_filter([
                'provider' => $context->provider,
                'tenant_id' => $context->tenantId,
                'user_type' => $context->userType,
                'channel' => $context->channel,
                'metadata' => $result->metadata,
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    private function serializeUser(Authenticatable $user): array
    {
        $fields = config('federated-auth.response.user_fields', ['id', 'name', 'email']);
        $payload = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $value = data_get($user, $field);

            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        $payload['auth_identifier'] = $user->getAuthIdentifier();

        return $payload;
    }

    private function authPayload(array $tokens): array
    {
        $auth = [];

        foreach (['token', 'access_token', 'token_type', 'expires_in', 'refresh_token', 'refresh_expires_in'] as $key) {
            if (array_key_exists($key, $tokens)) {
                $auth[$key] = $tokens[$key];
            }
        }

        return $auth;
    }
}
