# 02 - Configuration line by line

The main file is `config/federated-auth.php`.

## Global switch

```php
'enabled' => env('FEDERATED_AUTH_ENABLED', true),
```

- If `true`, the package accepts login requests.
- If `false`, every operation is rejected.
- Use this as an emergency rollback switch.

## Route switch

```php
'routes' => [
    'enabled' => env('FEDERATED_AUTH_ROUTES_ENABLED', true),
]
```

- If `true`, package routes are registered automatically.
- If `false`, you create your own routes and controllers.
- Use `false` when your project has module-specific routes or custom middleware chains.

## Route prefix

```php
'prefix' => env('FEDERATED_AUTH_ROUTES_PREFIX', 'api/auth/federated'),
```

Creates routes like:

```text
GET  /api/auth/federated/providers
GET  /api/auth/federated/google/redirect
GET  /api/auth/federated/google/callback
POST /api/auth/federated/google/token
```

## Public middleware

```php
'middleware' => ['api'],
```

Used for login routes that cannot require authentication yet.

## Protected middleware

```php
'protected_middleware' => ['api', 'auth:api'],
```

Used for linking and unlinking external identities from an already logged-in local user.

## Provider `enabled`

```php
'enabled' => env('FEDERATED_AUTH_GOOGLE_ENABLED', false),
```

- Enables or disables one provider independently.
- Allows rolling out Google before Facebook or Keycloak.

## Provider `driver`

```php
'driver' => 'socialite',
```

Supported values:

- `socialite`: Google/Facebook style OAuth through Laravel Socialite.
- `oidc`: generic OpenID Connect provider.
- `keycloak`: Keycloak-specific convenience wrapper around OIDC.

## `require_email`

```php
'require_email' => true,
```

If true, the provider must return an email. For most apps this should be true.

## `require_verified_email`

```php
'require_verified_email' => true,
```

If true, the email must be verified by the external provider.

## `auto_provision`

```php
'auto_provision' => true,
```

Allows creating a local user if no identity link exists.

Important: the package does not create users by itself unless a real `UserProvisionerInterface` is configured. The default provisioner throws an exception by design.

## `allow_email_linking`

```php
'allow_email_linking' => false,
```

If true, an external identity may link to an existing local user with the same email.

Recommended default: `false`.

Why:

- email may not be unique;
- email may not be verified;
- the same email can exist as Client and Veterinarian;
- automatic linking can become account takeover.

## Local user model

```php
'user' => [
    'model' => env('FEDERATED_AUTH_USER_MODEL', null),
]
```

Set your real user model:

```php
App\\Models\\User::class
Modules\\security\\Models\\Users::class
```

## Local user columns

```php
'columns' => [
    'email' => 'email',
    'status' => 'status_id',
    'type' => 'user_type',
]
```

This allows the package to resolve users in non-standard schemas.

## Identity store

```php
'identity_store' => [
    'connection' => null,
    'table' => 'federated_auth_identities',
]
```

This table links local users to external identities using:

```text
tenant_id + provider + provider_user_id
```

For Ronu this can be changed to:

```php
'table' => 'security.social_accounts'
```

## Bindings

```php
'bindings' => [
    UserProvisionerInterface::class => NullUserProvisioner::class,
]
```

This is the customization heart of the package. Replace defaults with project-specific classes.
