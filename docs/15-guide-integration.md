# 15 - Junior guide: integrating `laravel-federated-auth` with `rest-generic-class`

> This guide explains, step by step and with simple examples, how to use the optional integration between `ronu/laravel-federated-auth` and `ronu/rest-generic-class`.
>
> Goal: help a learner understand how to return more homogeneous responses and how to include effective permissions in the login without coupling federated authentication to the generic CRUD.

---

## 1. The idea in one sentence

```text
laravel-federated-auth authenticates.
rest-generic-class helps present permissions and responses in a homogeneous way.
```

They are not the same thing.

`laravel-federated-auth` answers:

```text
Who are you?
```

`rest-generic-class` helps answer:

```text
What permissions do you have?
How do we expose REST data consistently?
```

---

## 2. What was implemented in the library

Two small contracts were added:

```php
AuthResponseFormatterInterface
PermissionPayloadResolverInterface
```

Implementations were also added:

```text
DefaultAuthResponseFormatter
NullPermissionPayloadResolver
RestGenericClassDetector
RestGenericPermissionPayloadResolver
RestGenericAuthResponseFormatter
```

Simple reading:

| Class | What it is for |
|---|---|
| `AuthResponseFormatterInterface` | Defines how the final login response is formatted. |
| `DefaultAuthResponseFormatter` | Keeps the package's classic response. |
| `RestGenericAuthResponseFormatter` | Returns an `ok/data/meta`-style response. |
| `PermissionPayloadResolverInterface` | Defines how to append permissions to the login. |
| `NullPermissionPayloadResolver` | Adds no permissions; safe by default. |
| `RestGenericPermissionPayloadResolver` | If RGC is installed and the user satisfies the contracts, appends effective permissions. |
| `RestGenericClassDetector` | Detects at runtime whether RGC is available. |

---

## 3. Why it was done with contracts

A junior might think:

```text
If we want to use RGC, let's make RGC a hard dependency.
```

That would be a mistake.

Federated authentication must be able to work in projects that do not use RGC.

That is why it was done like this:

```text
Independent core
    + small contracts
    + optional integration
```

This enables four scenarios:

```text
Project A: laravel-federated-auth only
Project B: laravel-federated-auth + rest-generic-class
Project C: laravel-federated-auth + Spatie directly
Project D: laravel-federated-auth + custom permissions
```

---

## 4. Optional installation

First you install auth:

```bash
composer require ronu/laravel-federated-auth
```

If you also want RGC:

```bash
composer require ronu/rest-generic-class
```

Important:

```text
ronu/rest-generic-class appears as a suggest, not as a require.
```

That means:

```text
Recommended for optional integration, but not mandatory.
```

---

## 5. Basic configuration without RGC

By default, the package uses:

```php
PermissionPayloadResolverInterface::class => NullPermissionPayloadResolver::class,
AuthResponseFormatterInterface::class => DefaultAuthResponseFormatter::class,
```

This means:

```text
No permissions are appended.
The response stays close to the original format.
```

Example response:

```json
{
  "success": true,
  "user": {
    "id": 25,
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 25
  },
  "access_token": "jwt-token",
  "token_type": "bearer",
  "was_provisioned": false,
  "was_linked": false,
  "metadata": []
}
```

---

## 6. Enabling RGC permissions in the login response

In `config/federated-auth.php`, change the bindings:

```php
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericAuthResponseFormatter;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericPermissionPayloadResolver;

'bindings' => [
    // other bindings...

    PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
    AuthResponseFormatterInterface::class => RestGenericAuthResponseFormatter::class,
],
```

Enable permissions in the response:

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
FEDERATED_AUTH_RGC_ENABLED=true
```

Note:

```text
FEDERATED_AUTH_RGC_ENABLED documents the project's intent.
The real detector checks whether the RGC interfaces exist.
```

---

## 7. How your User must be prepared

For RGC to return permissions, your user must implement:

```php
Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles
```

Example using Spatie:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements ProvidesRoles
{
    use HasRoles;
    use HasReadableUserPermissions;

    // If your roles relation is named 'roles', you do not need to declare anything else.
}
```

If your relation is not named `roles`, for example it is named `array_role`:

```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    const ROLES_RELATION = 'array_role';

    public function array_role()
    {
        return $this->belongsToMany(Role::class, 'role_users');
    }
}
```

---

## 8. How your Role must be prepared

Your Role model must implement:

```php
Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions
```

Example:

```php
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Traits\HasReadableRolePermissions;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole implements ProvidesRolePermissions
{
    use HasReadableRolePermissions;
}
```

That trait knows how to read the `enabled_permissions` relation when it exists.

---

## 9. Complete flow with RGC enabled

```text
1. The user signs in with Google.
2. laravel-federated-auth validates Google.
3. The ExternalIdentity is obtained.
4. The local user is resolved.
5. A local JWT is issued.
6. AuthResponseFormatterInterface formats the response.
7. PermissionPayloadResolverInterface attempts to append permissions.
8. RestGenericPermissionPayloadResolver checks:
      - Is RGC installed?
      - Does User implement ProvidesRoles?
      - Does User have permissionsPayload()?
9. If everything is fine, it appends permissions.
10. If something is missing, it does not break the login; it simply omits permissions.
```

This is very important:

```text
A failure of optional permissions must not block authentication.
```

---

## 10. RGC-style response

With `RestGenericAuthResponseFormatter`, the response looks like this:

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 25,
      "email": "client@example.com",
      "user_type": "Client",
      "auth_identifier": 25
    },
    "auth": {
      "token": "jwt-token",
      "access_token": "jwt-token",
      "token_type": "bearer",
      "expires_in": 60
    },
    "federated": {
      "provider": "google",
      "was_provisioned": false,
      "was_linked": false
    },
    "permissions": {
      "count": 3,
      "permissions": [
        {
          "id": 1,
          "name": "appointments.index",
          "module": "medical",
          "guard": "api"
        }
      ]
    }
  },
  "meta": {
    "provider": "google",
    "user_type": "Client",
    "channel": "mobile"
  }
}
```

This format is more convenient for frontends because it separates:

```text
user        -> user data
auth        -> token data
federated   -> external login data
permissions -> effective permissions
meta        -> request context
```

---

## 11. What happens if RGC is not installed

If you accidentally configure the RGC resolver but RGC is not installed, the detector prevents it.

Result:

```text
No permissions are added.
The login is not broken.
```

The goal is to keep both security and availability.

---

## 12. Common errors

### 12.1. Permissions do not appear in the response

Check:

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
```

Check the bindings:

```php
PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
```

Check that the User model implements:

```php
ProvidesRoles
```

Check that it uses:

```php
HasReadableUserPermissions
```

### 12.2. RGC contract error

If RGC says a contract is missing, it is usually because:

```text
User does not implement ProvidesRoles
Role does not implement ProvidesRolePermissions
the roles relation has another name and you did not declare ROLES_RELATION
```

### 12.3. Login works but permissions is empty

This may be correct if the user has no roles or permissions.

Test the RGC endpoint:

```http
GET /api/permissions
Authorization: Bearer <token>
```

If that endpoint returns no permissions, the problem is in the RGC/roles configuration, not in federated auth.

---

## 13. Security for juniors

Do not do this:

```text
Grant the Admin role because someone signed in with Google.
```

Google confirms identity, not authority within your business.

Correct:

```text
Google/Facebook/Apple -> Client
Keycloak/OIDC enterprise -> roles mappable with an allowlist
```

Mental allowlist example:

```php
[
    'keycloak-admin' => 'Admin',
    'keycloak-vet' => 'Veterinarian',
    'keycloak-client' => 'Client',
]
```

---

## 14. How to test it manually

### Case A: without permissions

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=false
```

Expected login:

```text
Returns user + token.
Does not return permissions.
```

### Case B: with RGC permissions

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
FEDERATED_AUTH_RGC_ENABLED=true
```

Bindings:

```php
PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
AuthResponseFormatterInterface::class => RestGenericAuthResponseFormatter::class,
```

Expected login:

```text
Returns ok/data/meta.
Inside data it returns user, auth, federated and permissions.
```

---

## 15. Junior checklist

- [ ] Installed `ronu/laravel-federated-auth`.
- [ ] Installed `ronu/rest-generic-class` only if I want the permissions/response integration.
- [ ] Configured Google/Facebook/Apple/Keycloak.
- [ ] Set `FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true` if I want permissions in the login.
- [ ] Changed `PermissionPayloadResolverInterface` to the RGC resolver.
- [ ] Changed `AuthResponseFormatterInterface` to the RGC formatter if I want `ok/data/meta`.
- [ ] My User implements `ProvidesRoles`.
- [ ] My Role implements `ProvidesRolePermissions`.
- [ ] Tested `/api/permissions` with the local token.
- [ ] Verified that Admin is not created automatically from Google/Facebook/Apple.

---

## 16. Final summary

The correct integration is optional:

```text
If RGC exists, we enrich.
If RGC does not exist, we authenticate anyway.
```

The key sentence:

```text
Do not couple the entry door to the CRUD library.
Connect both through small contracts.
```
