<?php

use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityLinkRepositoryInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface;
use Ronu\LaravelFederatedAuth\Repositories\DatabaseIdentityLinkRepository;
use Ronu\LaravelFederatedAuth\Services\CacheOAuthStateStore;
use Ronu\LaravelFederatedAuth\Services\ConfigurableUserResolver;
use Ronu\LaravelFederatedAuth\Services\DefaultUserStatusChecker;
use Ronu\LaravelFederatedAuth\Services\Errors\ConfigurableErrorReporter;
use Ronu\LaravelFederatedAuth\Services\NoopRoleMapper;
use Ronu\LaravelFederatedAuth\Services\NullUserProvisioner;
use Ronu\LaravelFederatedAuth\Services\Permissions\NullPermissionPayloadResolver;
use Ronu\LaravelFederatedAuth\Services\Responses\DefaultAuthResponseFormatter;
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
            // Optional provider authorization-screen parameters. Protected
            // transaction fields such as state, nonce and PKCE are ignored.
            'authorization_params' => array_filter([
                'prompt' => env('FEDERATED_AUTH_GOOGLE_PROMPT'),
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'stateless' => env('FEDERATED_AUTH_STATELESS', true),
            'require_email' => true,
            'require_verified_email' => true,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
            'supports_nonce' => true,
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
            'trust_email_as_verified' => false,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
            'supports_nonce' => false,
        ],

        'apple' => [
            'enabled' => env('FEDERATED_AUTH_APPLE_ENABLED', false),
            'driver' => 'apple',
            'client_id' => env('APPLE_CLIENT_ID'),
            'team_id' => env('APPLE_TEAM_ID'),
            'key_id' => env('APPLE_KEY_ID'),
            'private_key' => env('APPLE_PRIVATE_KEY'),
            'private_key_path' => env('APPLE_PRIVATE_KEY_PATH'),
            'client_secret' => env('APPLE_CLIENT_SECRET'),
            'client_secret_ttl_seconds' => env('APPLE_CLIENT_SECRET_TTL_SECONDS', 86400),
            'redirect_uri' => env('APPLE_REDIRECT_URI'),
            'issuer' => 'https://appleid.apple.com',
            'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize',
            'token_endpoint' => 'https://appleid.apple.com/auth/token',
            'userinfo_endpoint' => null,
            'jwks_uri' => 'https://appleid.apple.com/auth/keys',
            'scopes' => ['name', 'email'],
            'response_mode' => 'form_post',
            'require_email' => true,
            'require_verified_email' => true,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
            'supports_nonce' => true,
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
            'supports_nonce' => true,
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

        'oauth_state' => [
            'enabled' => env('FEDERATED_AUTH_OAUTH_STATE_ENABLED', true),
            'ttl_seconds' => env('FEDERATED_AUTH_OAUTH_STATE_TTL_SECONDS', 300),
            'cache_prefix' => env('FEDERATED_AUTH_OAUTH_STATE_CACHE_PREFIX', 'federated-auth:oauth-state:'),
            'bind_user_agent' => env('FEDERATED_AUTH_OAUTH_STATE_BIND_USER_AGENT', true),
            'bind_ip' => env('FEDERATED_AUTH_OAUTH_STATE_BIND_IP', false),
        ],

        'pkce' => [
            'enabled' => env('FEDERATED_AUTH_PKCE_ENABLED', true),
            'method' => 'S256',
        ],

        'oidc' => [
            'nonce_enabled' => env('FEDERATED_AUTH_OIDC_NONCE_ENABLED', true),
            'require_azp_when_multiple_audiences' => true,

            // Small tolerance for normal clock skew between an identity
            // provider and the application server. It applies to the provider
            // token's iat, nbf and exp claims; zero restores strict comparison.
            'clock_skew_seconds' => env('FEDERATED_AUTH_OIDC_CLOCK_SKEW_SECONDS', 60),
        ],

        'redirects' => [
            'allowed_hosts' => env('FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS', ''),
            'allow_http_localhost' => env('FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Optional structured trace of every federated flow, driven by the package
    | events. Off by default so upgrading never starts writing to your log
    | unasked; listen to the events yourself if you want a different shape.
    |
    | The raw OAuth state is never written — only a truncated, non-reversible
    | digest, which is enough to join the redirect line to its callback line
    | while being useless to anyone who reads the log.
    |
    */
    'logging' => [
        'enabled' => env('FEDERATED_AUTH_LOGGING_ENABLED', false),

        // Null uses the application's default log channel.
        'channel' => env('FEDERATED_AUTH_LOG_CHANNEL'),

        // Per-event level overrides.
        'levels' => [
            'redirect_issued' => 'info',
            'login_succeeded' => 'info',
            'login_failed' => 'warning',
            'user_provisioned' => 'info',
            'account_linked' => 'info',
        ],

        // Email addresses are personal data; opt in explicitly.
        'include_email' => env('FEDERATED_AUTH_LOG_INCLUDE_EMAIL', false),

        // Sanitized exception messages, including wrapped provider causes.
        'exception_chain_limit' => env('FEDERATED_AUTH_LOG_EXCEPTION_CHAIN_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error reporting
    |--------------------------------------------------------------------------
    |
    | Durable capture of every failure the broker raises — redirect, callback,
    | token login, link and unlink. The package owns no error table: it
    | normalizes the failure into a FederatedAuthError and hands it to the
    | handlers below, which is where the host's own error log takes over.
    |
    | Each handler may be:
    |
    |   1. A class implementing ErrorReporterInterface — receives the DTO:
    |          Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface
    |   2. A queued job — constructed with the payload array and dispatched:
    |          App\Jobs\LogErrorToDatabase::class
    |   3. 'Service@method' — an existing service called with (payload, error):
    |          'App\Services\ErrorLogService@store'
    |   4. Any invokable class or closure — called with (payload, error).
    |
    | Handlers are called with two arguments; a handler that only declares the
    | first one still works, so an existing `handle(array $data)` needs no
    | change. Nothing here runs until `enabled` is true.
    |
    */
    'error_reporting' => [
        'enabled' => env('FEDERATED_AUTH_ERROR_REPORTING_ENABLED', false),

        'handlers' => [
            // App\Jobs\LogErrorToDatabase::class,
        ],

        // Applied when a handler is a queued job.
        'queue' => [
            'connection' => env('FEDERATED_AUTH_ERROR_QUEUE_CONNECTION'),
            'queue' => env('FEDERATED_AUTH_ERROR_QUEUE', 'logs'),

            // Defer the write until after the response is sent. Requires the
            // terminable middleware stack, so it does nothing under queue:work
            // or in tests — off by default to keep behaviour predictable.
            'after_response' => env('FEDERATED_AUTH_ERROR_QUEUE_AFTER_RESPONSE', false),
        ],

        // Exceptions never worth a row. Expired or replayed state is the normal
        // result of a user leaving a login tab open, not an incident.
        'ignore_exceptions' => [
            // Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException::class,
        ],

        // When non-empty, ONLY these are reported (evaluated after the ignore
        // list). Useful to narrow capture to genuine provider/infra failures.
        'only_exceptions' => [],

        'payload' => [
            // Stack frames help; frame *arguments* are never included, since the
            // provider token is an argument to half the frames in this package.
            'include_trace' => true,
            'trace_frames' => 15,

            'include_request' => true,
            'include_headers' => true,

            // Email is personal data, and on Apple it is a private-relay address.
            'include_email' => false,

            // Extra keys to redact, on top of the always-redacted OAuth set
            // (code, state, id_token, access_token, client_secret, authorization,
            // cookie, …) which cannot be disabled.
            'sensitive_keys' => [],

            // Restrict the payload to the columns your error table actually has.
            // Leave empty to pass every key through.
            //
            // Full key set: description, error, exception, code, file, line,
            // status_code, operation, provider, channel, user_type, tenant_id,
            // guard, state_digest, provider_user_id, email, ip, path,
            // parameters, request, headers, user_id, username, created_at,
            // updated_at.
            'only' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted route context
    |--------------------------------------------------------------------------
    |
    | Route-default keys read by AuthContext::fromTrustedRoute(). Applications
    | that resolve the channel from the URL should register their routes with
    | these defaults and build the context from them, so the values that pick
    | the OAuth client, the callback URI and the required user type can never be
    | supplied by the caller.
    |
    */
    'trusted_route' => [
        'keys' => [
            'channel' => 'trusted_channel',
            'user_type' => 'trusted_user_type',
            'tenant_id' => 'trusted_tenant_id',
            'guard' => 'trusted_guard',
        ],
    ],

    'response' => [
        'include_user' => true,
        'user_fields' => ['id', 'uuid', 'name', 'email', 'username', 'user_type', 'status_id', 'avatar'],
        'include_external_identity' => false,
        'include_permissions' => env('FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS', false),
    ],

    'integrations' => [
        'rest_generic_class' => [
            'enabled' => env('FEDERATED_AUTH_RGC_ENABLED', false),
            'log_permission_errors' => env('FEDERATED_AUTH_RGC_LOG_PERMISSION_ERRORS', false),
        ],
    ],

    'bindings' => [
        IdentityLinkRepositoryInterface::class => DatabaseIdentityLinkRepository::class,
        OAuthStateStoreInterface::class => CacheOAuthStateStore::class,
        UserResolverInterface::class => ConfigurableUserResolver::class,
        UserProvisionerInterface::class => NullUserProvisioner::class,
        TokenIssuerInterface::class => JwtAuthTokenIssuer::class,
        UserStatusCheckerInterface::class => DefaultUserStatusChecker::class,
        RoleMapperInterface::class => NoopRoleMapper::class,
        PermissionPayloadResolverInterface::class => NullPermissionPayloadResolver::class,
        AuthResponseFormatterInterface::class => DefaultAuthResponseFormatter::class,

        // Reads `error_reporting.handlers` above. Swap for your own class to
        // take full control, or for NullErrorReporter to disable capture
        // outright regardless of the config block.
        ErrorReporterInterface::class => ConfigurableErrorReporter::class,
    ],
];
