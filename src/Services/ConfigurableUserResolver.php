<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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
        $this->applyContextConstraint($query, $context);

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
        $this->applyContextConstraint($query, $context);
        $users = $query->limit(2)->get();

        if ($users->count() > 1 && config('federated-auth.security.deny_ambiguous_email_match', true)) {
            throw new AmbiguousLocalUserException('More than one local user matches the external provider email.');
        }

        return $users->first();
    }

    private function applyContextConstraint(Builder $query, AuthContext $context): void
    {
        if (! $context->userType || $this->isTrustedMultiRoleRegistration($context)) {
            return;
        }

        $roleAware = config('federated-auth.security.role_aware_user_resolution', []);
        $enabled = is_array($roleAware) && ($roleAware['enabled'] ?? false) === true;
        $map = $enabled && is_array($roleAware['map'] ?? null)
            ? $roleAware['map']
            : [];
        $requiredRole = $map[$context->userType] ?? null;
        $relation = is_string($roleAware['relation'] ?? null)
            ? $roleAware['relation']
            : 'roles';
        $column = is_string($roleAware['column'] ?? null)
            ? $roleAware['column']
            : 'name';

        if (
            $enabled
            && is_string($requiredRole)
            && $requiredRole !== ''
            && method_exists($query->getModel(), $relation)
        ) {
            // The host relation should expose only approved/active roles. This
            // allows a historical user_type=Client account with an active Admin
            // role to authenticate as Admin, while a pending disabled role is
            // rejected by the relation itself.
            $query->whereHas(
                $relation,
                static fn (Builder $roles): Builder => $roles->where($column, $requiredRole),
            );

            return;
        }

        $typeColumn = config('federated-auth.user.columns.type');

        if ($typeColumn) {
            $query->where($typeColumn, $context->userType);
        }
    }

    /**
     * Cross-type resolution is permitted only when the host application opts in
     * globally and a server-created OAuth state restores the explicit marker.
     * AuthContext::fromRequest never accepts arbitrary metadata from the client,
     * so query/body/header manipulation cannot activate this path.
     */
    private function isTrustedMultiRoleRegistration(AuthContext $context): bool
    {
        $enabled = (bool) config(
            'federated-auth.security.allow_trusted_cross_user_type_resolution',
            false,
        );
        $trustedRegistration = ($context->metadata['trusted_multi_role_registration'] ?? false) === true;

        return $enabled && $trustedRegistration;
    }
}
