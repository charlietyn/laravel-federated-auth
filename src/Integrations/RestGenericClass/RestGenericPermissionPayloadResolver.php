<?php

namespace Ronu\LaravelFederatedAuth\Integrations\RestGenericClass;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;

class RestGenericPermissionPayloadResolver implements PermissionPayloadResolverInterface
{
    public function __construct(private readonly RestGenericClassDetector $detector) {}

    public function resolve(Authenticatable $user, AuthContext $context): array
    {
        if (! $this->detector->available()) {
            return [];
        }

        $providesRoles = $this->detector->providesRolesContract();

        if (! $user instanceof $providesRoles) {
            return [];
        }

        if (! method_exists($user, 'permissionsPayload')) {
            return [];
        }

        try {
            $request = $context->request ?: request();

            return $user->permissionsPayload($request, [
                'provider' => $context->provider,
                'tenant_id' => $context->tenantId,
                'user_type' => $context->userType,
                'channel' => $context->channel,
            ]);
        } catch (\Throwable $exception) {
            if ((bool) config('federated-auth.integrations.rest_generic_class.log_permission_errors', false)) {
                Log::warning('Unable to resolve rest-generic-class permission payload.', [
                    'exception' => $exception,
                    'user_id' => $user->getAuthIdentifier(),
                    'provider' => $context->provider,
                ]);
            }

            return [];
        }
    }
}
