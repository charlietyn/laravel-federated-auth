<?php

use Ronu\LaravelFederatedAuth\Contracts\IdentityLinkRepositoryInterface;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface;
use Ronu\LaravelFederatedAuth\Repositories\DatabaseIdentityLinkRepository;
use Ronu\LaravelFederatedAuth\Services\ConfigurableUserResolver;
use Ronu\LaravelFederatedAuth\Services\DefaultUserStatusChecker;
use Ronu\LaravelFederatedAuth\Services\NoopRoleMapper;
use Ronu\LaravelFederatedAuth\Services\NullUserProvisioner;
use Ronu\LaravelFederatedAuth\Services\TokenIssuers\JwtAuthTokenIssuer;

return [
    'enabled' => env('FEDERATED_AUTH_ENABLED', true),
    'routes' => [
        'enabled' => env('FEDERATED_AUTH_ROUTES_ENABLED', true),
        'prefix' => env('FEDERATED_AUTH_ROUTES_PREFIX', 'api/auth/federated'),
        'middleware' => ['api'],
        'protected_middleware' => ['api', 'auth:api'],
        'name_prefix' => 'federated-auth.',
    ],
    'providers' => [
        'google' => [
            'enabled' => env('FEDERATED_AUTH_GOOGLE_ENABLED', false),
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
            'scopes' => ['openid', 'profile', 'email'],
            'stateless' => env('FEDERATED_AUTH_STATELESS', true),
            'require_email' => true,
            'require_verified_email' => true,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
        ],
        'facebook' => [
            'enabled' => env('FEDERATED_AUTH_FACEBOOK_ENABLED', false),
            'driver' => 'socialite',
            'socialite_driver' => 'facebook',
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
            'scopes' => ['email'],
            'stateless' => env('FEDERATED_AUTH_STATELESS', true),
            'require_email' => true,
            'require_verified_email' => false,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
        ],
        'keycloak' => [
            'enabled' => env('FEDERATED_AUTH_KEYCLOAK_ENABLED', false),
            'driver' => 'keycloak',
            'base_url' => env('KEYCLOAK_BASE_URL'),
            'realm' => env('KEYCLOAK_REALM'),
            'issuer' => env('KEYCLOAK_ISSUER'),
            'client_id' => env('KEYCLOAK_CLIENT_ID'),
            'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
            'redirect_uri' => env('KEYCLOAK_REDIRECT_URI'),
            'authorization_endpoint' => env('KEYCLOAK_AUTHORIZATION_ENDPOINT'),
            'token_endpoint' => env('KEYCLOAK_TOKEN_ENDPOINT'),
            'userinfo_endpoint' => env('KEYCLOAK_USERINFO_ENDPOINT'),
            'jwks_uri' => env('KEYCLOAK_JWKS_URI'),
            'scopes' => ['openid', 'profile', 'email'],
            'require_email' => true,
            'require_verified_email' => true,
            'auto_provision' => false,
            'allow_email_linking' => false,
            'sync_roles' => true,
            'roles_claim' => 'realm_access.roles',
            'groups_claim' => 'groups',
        ],
    ],
    'user' => [
        'model' => env('FEDERATED_AUTH_USER_MODEL', null),
        'connection' => env('FEDERATED_AUTH_USER_CONNECTION', null),
        'table' => env('FEDERATED_AUTH_USER_TABLE', null),
        'primary_key' => env('FEDERATED_AUTH_USER_PRIMARY_KEY', 'id'),
        'columns' => [
            'id' => env('FEDERATED_AUTH_USER_ID_COLUMN', 'id'),
            'email' => env('FEDERATED_AUTH_USER_EMAIL_COLUMN', 'email'),
            'name' => env('FEDERATED_AUTH_USER_NAME_COLUMN', 'name'),
            'username' => env('FEDERATED_AUTH_USER_USERNAME_COLUMN', 'username'),
            'password' => env('FEDERATED_AUTH_USER_PASSWORD_COLUMN', 'password'),
            'avatar' => env('FEDERATED_AUTH_USER_AVATAR_COLUMN', 'avatar'),
            'status' => env('FEDERATED_AUTH_USER_STATUS_COLUMN', 'status_id'),
            'type' => env('FEDERATED_AUTH_USER_TYPE_COLUMN', 'user_type'),
        ],
        'active_status_values' => [1, '1', true, 'active', 'enabled'],
    ],
    'identity_store' => [
        'connection' => env('FEDERATED_AUTH_IDENTITY_CONNECTION', null),
        'table' => env('FEDERATED_AUTH_IDENTITY_TABLE', 'federated_auth_identities'),
        'tenant_column' => env('FEDERATED_AUTH_IDENTITY_TENANT_COLUMN', 'tenant_id'),
        'user_id_column' => env('FEDERATED_AUTH_IDENTITY_USER_ID_COLUMN', 'user_id'),
        'store_provider_tokens' => env('FEDERATED_AUTH_STORE_PROVIDER_TOKENS', false),
        'encrypt_provider_tokens' => env('FEDERATED_AUTH_ENCRYPT_PROVIDER_TOKENS', true),
    ],
    'security' => [
        'prevent_admin_auto_provision' => true,
        'admin_user_types' => ['Admin', 'SuperAdmin', 'Administrator'],
        'deny_ambiguous_email_match' => true,
        'deny_unverified_email_linking' => true,
        'deny_unlink_last_identity_without_password' => true,
    ],
    'bindings' => [
        IdentityLinkRepositoryInterface::class => DatabaseIdentityLinkRepository::class,
        UserResolverInterface::class => ConfigurableUserResolver::class,
        UserProvisionerInterface::class => NullUserProvisioner::class,
        TokenIssuerInterface::class => JwtAuthTokenIssuer::class,
        UserStatusCheckerInterface::class => DefaultUserStatusChecker::class,
        RoleMapperInterface::class => NoopRoleMapper::class,
    ],
];
