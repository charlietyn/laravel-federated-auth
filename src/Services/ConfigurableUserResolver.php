<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Exceptions\AmbiguousLocalUserException;

class ConfigurableUserResolver implements UserResolverInterface
{
    public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable
    {
        $model = config('federated-auth.user.model');

        if (! $model || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $query = $model::query()
            ->where(config('federated-auth.user.primary_key', 'id'), $userId);
        $typeColumn = config('federated-auth.user.columns.type');

        if ($typeColumn && $context->userType && $this->mustMatchUserType($context)) {
            $query->where($typeColumn, $context->userType);
        }

        return $query->first();
    }

    public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        if (! $identity->email) {
            return null;
        }

        $model = config('federated-auth.user.model');

        if (! $model || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $query = $model::query()
            ->where(config('federated-auth.user.columns.email', 'email'), $identity->email);
        $typeColumn = config('federated-auth.user.columns.type');

        if ($typeColumn && $context->userType && $this->mustMatchUserType($context)) {
            $query->where($typeColumn, $context->userType);
        }

        $users = $query->limit(2)->get();

        if ($users->count() > 1 && config('federated-auth.security.deny_ambiguous_email_match', true)) {
            throw new AmbiguousLocalUserException('More than one local user matches the external provider email.');
        }

        return $users->first();
    }

    /**
     * Cross-type resolution is permitted only when the host application opts in
     * globally and a server-created OAuth state restores the explicit marker.
     * AuthContext::fromRequest never accepts arbitrary metadata from the client,
     * so query/body/header manipulation cannot activate this path.
     */
    private function mustMatchUserType(AuthContext $context): bool
    {
        $enabled = (bool) config(
            'federated-auth.security.allow_trusted_cross_user_type_resolution',
            false,
        );
        $trustedRegistration = ($context->metadata['trusted_multi_role_registration'] ?? false) === true;

        return ! ($enabled && $trustedRegistration);
    }
}
