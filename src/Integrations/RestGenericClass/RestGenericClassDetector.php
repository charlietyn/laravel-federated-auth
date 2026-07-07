<?php

namespace Ronu\LaravelFederatedAuth\Integrations\RestGenericClass;

class RestGenericClassDetector
{
    public function available(): bool
    {
        return interface_exists($this->providesRolesContract())
            && interface_exists($this->providesRolePermissionsContract());
    }

    public function providesRolesContract(): string
    {
        return 'Ronu\\RestGenericClass\\Core\\Support\\Permissions\\Contracts\\ProvidesRoles';
    }

    public function providesRolePermissionsContract(): string
    {
        return 'Ronu\\RestGenericClass\\Core\\Support\\Permissions\\Contracts\\ProvidesRolePermissions';
    }
}
