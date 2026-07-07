# Laravel Federated Auth

<p align="center">
  <strong>Federated authentication bridge for Laravel applications with custom users, tenants, guards and OAuth/OIDC providers.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/ronu/laravel-federated-auth"><img src="https://img.shields.io/packagist/v/ronu/laravel-federated-auth.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/ronu/laravel-federated-auth"><img src="https://img.shields.io/packagist/php-v/ronu/laravel-federated-auth.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/ronu/laravel-federated-auth"><img src="https://img.shields.io/packagist/l/ronu/laravel-federated-auth.svg?style=flat-square" alt="License"></a>
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?style=flat-square&logo=laravel" alt="Laravel 11 or 12">
  <img src="https://img.shields.io/badge/OIDC-ready-blue?style=flat-square" alt="OIDC ready">
</p>

---

## What is this package?

`ronu/laravel-federated-auth` is a **framework-level Laravel package** for authenticating users through external identity providers while keeping your local application architecture under your control.

It supports:

- Google
- Facebook
- Sign in with Apple
- Keycloak
- Generic OpenID Connect / OAuth2 providers
- Custom local user models
- Custom user tables and primary keys
- Multi-tenant identity links
- API guards and custom token issuers
- Local user provisioning
- Role mapping
- Optional integration with `ronu/rest-generic-class`

This package is intentionally **not** a simple social-login wrapper.

It is designed for real production systems where authentication is only one part of a larger architecture.

---

## The problem it solves

Most social-login examples assume something like this:

```text
users.id
users.email is unique
App\Models\User
Laravel Sanctum or session auth
single tenant
simple redirect login
```

Real projects are often different:

```text
mod_security.users.uuid
user_type = Client | Admin | Veterinarian | Technician
tenant_id / clinic_id / organization_id
JWT auth instead of Sanctum
custom role tables
Keycloak enterprise login
mobile clients sending id_token directly
Apple private relay emails
providers that do not always return verified emails
```

This package gives you a clean bridge between:

```text
External provider identity
        ↓
Normalized ExternalIdentity DTO
        ↓
Your local user resolution / provisioning rules
        ↓
Your local identity link table
        ↓
Your local token issuer
```

---

## Core idea

```text
Provider
   ↓
Adapter
   ↓
ExternalIdentity
   ↓
UserResolver / UserProvisioner
   ↓
IdentityLinkRepository
   ↓
RoleMapper
   ↓
TokenIssuer
   ↓
AuthResult
```

The provider tells you **who the person is externally**.

Your application decides:

- which local user this maps to;
- whether that user can log in;
- which tenant the login belongs to;
- which roles or permissions apply;
- which local token should be issued.

---

## Requirements

| Dependency | Version |
|---|---:|
| PHP | `^8.2` |
| Laravel / Illuminate | `^11.0` or `^12.0` |
| Laravel Socialite | `^5.15` |
| Guzzle | `^7.8` |
| firebase/php-jwt | `^6.10` |

---

## Installation

```bash
composer require ronu/laravel-federated-auth
```

Publish configuration:

```bash
php artisan vendor:publish --tag=federated-auth-config
```

Publish migrations:

```bash
php artisan vendor:publish --tag=federated-auth-migrations
```

Run migrations:

```bash
php artisan migrate
```

Optional: publish the documentation into your Laravel app:

```bash
php artisan vendor:publish --tag=federated-auth-docs
```

---

## Quick configuration

Enable providers in `.env`:

```env
FEDERATED_AUTH_ENABLED=true
FEDERATED_AUTH_ROUTES_ENABLED=true
FEDERATED_AUTH_ROUTES_PREFIX=api/auth/federated

FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback

FEDERATED_AUTH_APPLE_ENABLED=true
APPLE_CLIENT_ID=com.example.web
APPLE_TEAM_ID=TEAMID1234
APPLE_KEY_ID=ABC123DEFG
APPLE_PRIVATE_KEY_PATH=/secure/path/AuthKey_ABC123DEFG.p8
APPLE_REDIRECT_URI=https://api.example.com/api/auth/federated/apple/callback

FEDERATED_AUTH_KEYCLOAK_ENABLED=true
KEYCLOAK_BASE_URL=https://auth.example.com
KEYCLOAK_REALM=my-realm
KEYCLOAK_CLIENT_ID=my-api-client
KEYCLOAK_CLIENT_SECRET=secret
KEYCLOAK_REDIRECT_URI=https://api.example.com/api/auth/federated/keycloak/callback
```

---

## Routes

When package routes are enabled, the package exposes:

| Method | URI | Purpose |
|---|---|---|
| `GET` | `/api/auth/federated/providers` | List configured providers. |
| `GET` | `/api/auth/federated/{provider}/redirect` | Start a browser redirect flow. |
| `GET/POST` | `/api/auth/federated/{provider}/callback` | Handle provider callback. |
| `POST` | `/api/auth/federated/{provider}/token` | Native/mobile token login. |
| `POST` | `/api/auth/federated/{provider}/link` | Link provider identity to authenticated user. |
| `DELETE` | `/api/auth/federated/{provider}/unlink` | Unlink provider identity from authenticated user. |

---

## Browser redirect flow

```text
Frontend / Browser
   ↓
GET /api/auth/federated/google/redirect
   ↓
Package creates one-time OAuthAuthorizationState
   ↓
Redirect to Google
   ↓
Google callback with code + state
   ↓
Package consumes state once
   ↓
Package restores tenant/user_type/channel/guard from state
   ↓
Provider identity is normalized
   ↓
Local user is resolved or provisioned
   ↓
Local API token is issued
```

Example:

```http
GET /api/auth/federated/google/redirect?user_type=Client&tenant_id=clinic-1&channel=web
```

The callback normally receives only:

```text
code
state
```

The package restores the original application context from the consumed state before resolving or creating the local identity link.

---

## Native / mobile token flow

Mobile clients can authenticate directly with the provider SDK and send the provider token to Laravel.

### ID token

```http
POST /api/auth/federated/keycloak/token
Content-Type: application/json

{
  "id_token": "provider-id-token",
  "user_type": "Client",
  "tenant_id": "clinic-1",
  "channel": "mobile"
}
```

### Access token

```http
POST /api/auth/federated/keycloak/token
Content-Type: application/json

{
  "access_token": "provider-access-token",
  "user_type": "Client",
  "tenant_id": "clinic-1",
  "channel": "mobile"
}
```

For OIDC providers, the package preserves which field was submitted:

| Submitted field | Behavior |
|---|---|
| `id_token` | Validate/decode as an OIDC ID token. |
| `access_token` | Use `userinfo_endpoint` when configured. |
| unknown token type | JWT-looking tokens are treated as ID tokens; otherwise userinfo is used when available. |

This prevents native-client ID tokens from being sent to `userinfo_endpoint` as bearer access tokens.

---

## Example API response

Default response shape:

```json
{
  "success": true,
  "was_provisioned": false,
  "was_linked": false,
  "user": {
    "id": 25,
    "uuid": "4d78f4fb-70ef-45ef-b98a-d143d39464a3",
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 25
  },
  "access_token": "local-jwt-token",
  "token_type": "bearer",
  "expires_in": 3600,
  "metadata": []
}
```

User fields are configurable so the package does not leak sensitive model columns.

---

## Security model

This package separates provider identity from local authorization.

### Hardened redirect flows

Redirect-based flows can use:

- one-time OAuth state;
- state replay protection;
- user-agent/IP fingerprint binding;
- OIDC nonce;
- PKCE for OIDC adapters that control code exchange;
- redirect URI host validation;
- callback context restoration from stored state.

```env
FEDERATED_AUTH_OAUTH_STATE_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_TTL_SECONDS=300
FEDERATED_AUTH_OAUTH_STATE_BIND_USER_AGENT=true
FEDERATED_AUTH_OAUTH_STATE_BIND_IP=false

FEDERATED_AUTH_PKCE_ENABLED=true
FEDERATED_AUTH_OIDC_NONCE_ENABLED=true

FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com,app.example.com
FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS=false
```

### Safe defaults

By default, the package avoids dangerous assumptions:

- provider identity does not equal local authorization;
- Facebook email verification is not trusted unless explicitly enabled;
- provider tokens are not stored by default;
- admin auto-provisioning is denied by default;
- external identity keys use `provider + provider_user_id`, not email;
- unlinking the last external identity from a passwordless account can be denied.

---

## Local identity storage

The identity link table stores the relationship between a provider account and a local user.

Conceptually:

```text
tenant_id + provider + provider_user_id → local_user_id
```

Example:

```text
tenant_id = clinic-1
provider = google
provider_user_id = 123456789
user_id = 25
```

This is critical for multi-tenant applications because the same provider user may exist in different business contexts.

---

## Extending the package

The package is contract-first.

You can replace the default behavior by binding your own implementations:

| Contract | Responsibility |
|---|---|
| `UserResolverInterface` | Resolve a local user by ID, email or provider identity. |
| `UserProvisionerInterface` | Create a local user when auto-provisioning is allowed. |
| `IdentityLinkRepositoryInterface` | Store provider/local identity links. |
| `TokenIssuerInterface` | Issue the local application token. |
| `RoleMapperInterface` | Sync local roles from provider claims. |
| `UserStatusCheckerInterface` | Block disabled, inactive or forbidden users. |
| `OAuthStateStoreInterface` | Store and consume redirect-flow state. |
| `AuthResponseFormatterInterface` | Format the final API response. |
| `PermissionPayloadResolverInterface` | Optionally append permissions to login response. |

Example binding:

```php
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use App\Auth\JwtTokenIssuer;

'bindings' => [
    TokenIssuerInterface::class => JwtTokenIssuer::class,
],
```

---

## Custom user provisioning

A real application usually needs more than `User::create()`.

Example:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

final class ClientUserProvisioner implements UserProvisionerInterface
{
    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable
    {
        return DB::transaction(function () use ($identity, $context) {
            $user = User::create([
                'email' => $identity->email,
                'name' => $identity->name,
                'user_type' => $context->userType ?? 'Client',
                'status_id' => 1,
            ]);

            Client::create([
                'user_id' => $user->id,
                'tenant_id' => $context->tenantId,
            ]);

            $user->assignRole('Client');

            return $user;
        });
    }
}
```

---

## Role mapping

For enterprise providers such as Keycloak, you can map external groups/roles into local roles.

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

final class KeycloakRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        if (in_array('kwikvet-vet', $identity->roles, true)) {
            $user->syncRoles(['Veterinarian']);
        }

        if (in_array('kwikvet-client', $identity->roles, true)) {
            $user->syncRoles(['Client']);
        }
    }
}
```

Do not map privileged roles from public social providers such as Google, Facebook or Apple unless your governance process explicitly allows it.

---

## Optional integration with `ronu/rest-generic-class`

This package can work independently.

If `ronu/rest-generic-class` is installed, you can optionally enable response and permission payload integration.

```bash
composer require ronu/rest-generic-class
```

Then configure:

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
FEDERATED_AUTH_RGC_ENABLED=true
```

And bind:

```php
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericAuthResponseFormatter;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericPermissionPayloadResolver;

'bindings' => [
    PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
    AuthResponseFormatterInterface::class => RestGenericAuthResponseFormatter::class,
],
```

RGC-style response:

```json
{
  "ok": true,
  "data": {
    "user": {},
    "auth": {},
    "federated": {},
    "permissions": {}
  },
  "meta": {
    "provider": "keycloak",
    "tenant_id": "clinic-1",
    "channel": "mobile"
  }
}
```

The integration is optional and runtime-detected. The core package does not require `ronu/rest-generic-class`.

---

## Provider notes

### Google

Recommended for client login when email is verified.

```php
'google' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => true,
    'allowed_user_types' => ['Client'],
]
```

### Facebook

Facebook may not always return an email. Email verification trust is opt-in.

```php
'facebook' => [
    'require_email' => true,
    'require_verified_email' => false,
    'trust_email_as_verified' => false,
]
```

### Apple

Apple identity should be keyed by `sub`, not email, because Apple may return a private relay address.

```php
'apple' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => true,
    'allowed_user_types' => ['Client'],
]
```

### Keycloak / Generic OIDC

Recommended for enterprise authentication and controlled role mapping.

```php
'keycloak' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => false,
    'allow_email_linking' => false,
    'sync_roles' => true,
]
```

---

## Documentation

Full documentation lives in [`docs`](docs):

| File | Purpose |
|---|---|
| `00-guia-para-juniors.md` | Extended Spanish guide for apprentices. |
| `01-installation.md` | Installation steps. |
| `02-configuration-line-by-line.md` | Config explained line by line. |
| `03-core-architecture.md` | Internal architecture. |
| `04-google-facebook.md` | Google and Facebook setup. |
| `05-keycloak-oidc.md` | Keycloak and generic OIDC setup. |
| `06-kwikvet-integration-example.md` | Example integration in a modular Laravel system. |
| `07-extending-contracts.md` | How to replace package contracts. |
| `08-security-and-edge-cases.md` | Security scenarios and edge cases. |
| `09-testing.md` | Testing strategy. |
| `10-troubleshooting.md` | Common problems. |
| `11-line-by-line-request-flow.md` | Request lifecycle explained. |
| `12-oauth-hardening.md` | OAuth/OIDC hardening model. |
| `13-apple-provider.md` | Sign in with Apple. |
| `14-integracion-rest-generic-class.md` | Optional RGC integration analysis. |
| `15-guia-junior-integracion-rgc.md` | Junior guide for enabling RGC integration. |

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

Run code style checks:

```bash
vendor/bin/pint --test
```

---

## Production checklist

Before going live:

- [ ] Configure allowed redirect hosts.
- [ ] Keep OAuth state enabled.
- [ ] Keep OIDC nonce enabled.
- [ ] Keep PKCE enabled for OIDC code flows.
- [ ] Do not trust Facebook email verification unless intentionally configured.
- [ ] Do not auto-provision privileged users from public social providers.
- [ ] Confirm tenant scoping in `IdentityLinkRepository`.
- [ ] Confirm token issuer uses the expected guard.
- [ ] Confirm provider tokens are not stored unless needed.
- [ ] Run the test suite.
- [ ] Run static analysis / code style checks.

---

## Philosophy

This package follows a simple rule:

```text
External providers authenticate identity.
Your Laravel application owns authorization.
```

That separation keeps the package flexible enough for startups, SaaS products, enterprise systems and modular Laravel platforms.

---

## License

The MIT License (MIT). Please see [`LICENSE`](LICENSE) for more information.
