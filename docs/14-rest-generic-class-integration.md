# 14 - Optional integration with `ronu/rest-generic-class`

> This guide analyzes how `ronu/laravel-federated-auth` can coexist homogeneously with `ronu/rest-generic-class` without being rigidly coupled to it.
>
> Goal: leverage shared contracts, permissions, responses and conventions when both libraries are installed, while allowing `laravel-federated-auth` to keep working independently.

---

## 1. Executive summary

`rest-generic-class` and `laravel-federated-auth` solve different problems:

| Library | Main problem |
|---|---|
| `rest-generic-class` | Expose a generic RESTful CRUD with filters, relations, pagination, hierarchies, cache, validation and permissions. |
| `laravel-federated-auth` | Authenticate users through external providers such as Google, Facebook, Apple, Keycloak or OIDC and link them to local users. |

They must not be merged.

The correct integration is:

```text
laravel-federated-auth
    authenticates external identity
    resolves/provisions the local user
    issues the local token
    optionally queries permissions/roles via rest-generic-class if available
```

And:

```text
rest-generic-class
    keeps exposing CRUD, filters and permissions
    can use the user authenticated by laravel-federated-auth
    can protect fields/endpoints with effective roles and permissions
```

Core principle:

```text
Decoupled federated authentication.
Homogeneous REST authorization and exposure when RGC is present.
```

---

## 2. What `rest-generic-class` offers

According to its README, `rest-generic-class` provides base classes for RESTful CRUD with dynamic filtering, relation loading and hierarchical listings.

Its quickstart is based on three pieces:

```text
BaseModel
BaseService
RestController
```

Mental example:

```text
Product extends BaseModel
ProductService extends BaseService
ProductController extends RestController
```

The generic controller processes parameters such as:

```text
relations
_nested
soft_delete
attr / eq
select
pagination
orderby
oper
hierarchy
```

Then it delegates to the service:

```php
$params = $this->process_request($request);
return $this->service->list_all($params);
```

This means RGC is an excellent base for entity read/write endpoints, not necessarily for the OAuth callback.

---

## 3. Separation of responsibilities

### 3.1. `laravel-federated-auth`

Must take care of:

- building the redirect URL to Google/Facebook/Apple/Keycloak;
- validating the callback;
- validating state, nonce and PKCE;
- normalizing the external identity;
- looking up the external link;
- creating the local user when appropriate;
- syncing roles when applicable;
- issuing the local token.

### 3.2. `rest-generic-class`

Must take care of:

- generic CRUD;
- dynamic filters;
- controlled relation loading;
- pagination;
- hierarchies;
- exporting;
- read caching;
- effective permissions;
- field restriction by role.

### 3.3. Healthy boundary

`laravel-federated-auth` must not do this:

```text
extends RestController
mandatorily uses BaseService
requires BaseModel
requires RGC routes
requires Spatie directly
```

Because that would turn the auth library into a library dependent on CRUD.

If tomorrow a project uses plain Laravel, Sanctum and simple models, auth must work all the same.

---

## 4. Recommended points of homogeneity

The integration must be optional, in layers.

```text
Level 0: No integration
Level 1: Homogeneous response
Level 2: Effective permissions in the login response
Level 3: RoleMapper compatible with RGC contracts
Level 4: Social account models compatible with BaseModel/BaseService
Level 5: Administrative CRUD endpoints over social accounts using RGC
```

---

## 5. Level 0 - No integration

`laravel-federated-auth` works on its own.

```text
Google -> ExternalIdentity -> User -> Token
```

It does not need RGC.

This must always be preserved.

---

## 6. Level 1 - Homogeneous response

RGC usually returns structures such as:

```json
{
  "success": true,
  "model": { }
}
```

Or:

```json
{
  "data": [ ]
}
```

`laravel-federated-auth` returns something like:

```json
{
  "success": true,
  "user": { },
  "token": "...",
  "token_type": "bearer",
  "was_provisioned": true,
  "was_linked": true,
  "metadata": []
}
```

The recommended homogeneity is not to force exactly the same CRUD format, but to allow a common wrapper:

```json
{
  "ok": true,
  "data": {
    "user": { },
    "auth": {
      "access_token": "...",
      "token_type": "bearer",
      "expires_in": 3600
    },
    "federated": {
      "provider": "google",
      "was_provisioned": true,
      "was_linked": true
    }
  },
  "meta": {
    "request_id": "...",
    "channel": "mobile"
  }
}
```

Technical proposal:

```php
interface AuthResponseFormatterInterface
{
    public function format(AuthResult $result, AuthContext $context): array;
}
```

Implementations:

```text
DefaultAuthResponseFormatter
RestGenericClassAuthResponseFormatter
```

Config:

```php
'bindings' => [
    AuthResponseFormatterInterface::class => DefaultAuthResponseFormatter::class,
]
```

If the project wants RGC style:

```php
AuthResponseFormatterInterface::class => RestGenericClassAuthResponseFormatter::class,
```

---

## 7. Level 2 - Effective permissions in the login response

RGC has a permissions layer that allows obtaining the user's effective permissions:

```text
direct permissions ∪ permissions via roles
```

In RGC, the User model must implement:

```php
ProvidesRoles
```

And each Role must implement:

```php
ProvidesRolePermissions
```

The central resolver is:

```php
UserRolesResolver
```

Recommended flow on federated login:

```text
1. User authenticates with Google/Apple/etc.
2. laravel-federated-auth obtains the local user.
3. A local token is issued.
4. If RGC is installed and the user implements ProvidesRoles:
       effective permissions are appended to the payload.
5. Otherwise, it is silently skipped.
```

Example response:

```json
{
  "success": true,
  "user": {
    "id": 25,
    "email": "client@example.com",
    "user_type": "Client"
  },
  "access_token": "jwt-local-token",
  "permissions": {
    "count": 3,
    "items": [
      "appointments.view",
      "pets.create",
      "profile.update"
    ]
  }
}
```

Important rule:

```text
Do not make RGC a hard requirement.
Detect classes/interfaces at runtime.
```

Example:

```php
if (
    interface_exists(\Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles::class)
    && $user instanceof \Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles
) {
    // append effective permissions
}
```

---

## 8. Level 3 - RoleMapper compatible with RGC

`laravel-federated-auth` already has:

```php
RoleMapperInterface
```

The mapper can sync roles from Keycloak/OIDC onto the local user.

RGC already has a contract-based permissions architecture:

```text
User -> ProvidesRoles
Role -> ProvidesRolePermissions
UserRolesResolver
```

The recommended integration is to create an optional mapper:

```php
final class RestGenericRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        // Only sync if the provider is trusted for roles.
        if (!in_array($identity->provider, ['keycloak', 'oidc'], true)) {
            return;
        }

        // Map external groups/roles to local roles.
        // Do not assume Spatie directly here if you want to keep flexibility.
    }
}
```

Security recommendation:

```text
Google/Facebook/Apple must not assign administrative roles.
Keycloak/OIDC enterprise may map roles, but with an allowlist.
```

Allowlist example:

```php
'role_mapping' => [
    'keycloak' => [
        'ronu-admin' => 'Admin',
        'ronu-vet' => 'Veterinarian',
        'ronu-client' => 'Client',
    ],
]
```

---

## 9. Level 4 - External identity model compatible with BaseModel

RGC could administratively manage the external links table if there is a model such as:

```php
use Ronu\RestGenericClass\Core\Models\BaseModel;

class FederatedIdentity extends BaseModel
{
    protected $table = 'federated_auth_identities';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'provider_email_verified',
        'provider_name',
        'provider_avatar',
        'claims',
        'metadata',
        'last_login_at',
    ];

    const MODEL = 'federated_identity';

    const RELATIONS = ['user'];

    protected $casts = [
        'claims' => 'array',
        'metadata' => 'array',
        'provider_email_verified' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(config('federated-auth.user.model'), 'user_id');
    }
}
```

This would allow administrative endpoints such as:

```http
GET /api/admin/federated-identities?relations=["user:id,email,name"]
```

But be careful:

```text
Do not expose access_token or refresh_token through CRUD.
Do not allow free update of provider_user_id.
Do not allow manual create without rules.
Do not allow delete without an audit trail.
```

---

## 10. Level 5 - Optional administrative CRUD

If a project wants to manage external links from a panel, it can create:

```php
class FederatedIdentityService extends BaseService
{
    public function __construct()
    {
        parent::__construct(FederatedIdentity::class);
    }
}
```

```php
class FederatedIdentityController extends RestController
{
    protected $modelClass = FederatedIdentity::class;

    public function __construct(FederatedIdentityService $service)
    {
        $this->service = $service;
    }
}
```

Routes:

```php
Route::middleware(['api', 'auth:api', 'permission:security.federated-identities.index'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource('federated-identities', FederatedIdentityController::class)
            ->only(['index', 'show']);
    });
```

Recommendation:

```text
Only index/show at first.
Do not enable generic store/update/destroy for identity links.
```

If you need to unlink, use the safe endpoint of `laravel-federated-auth`:

```http
DELETE /api/auth/federated/{provider}/unlink
```

Not a direct generic DELETE over the table.

---

## 11. Cache integration

RGC caches read operations such as `list_all` and `get_one`.

Federated auth should not cache:

- OAuth callbacks;
- code-for-token exchange;
- state validation;
- local token issuance;
- user provisioning.

If an administrative CRUD for `FederatedIdentity` is exposed, that CRUD may use cache for listings, provided that:

```text
create/update/delete/unlink bump the cache version or invalidate the cache.
```

RGC already bumps a cache version when a successful write occurs in `create`, `update`, `destroy` and related operations.

Recommendation:

```text
Do not mix the RGC cache with the OAuth state cache.
```

Two distinct caches:

| Cache | Use |
|---|---|
| OAuth state cache | Security of the redirect login, short TTL, one-time. |
| RGC read cache | Optimize CRUD listings/reads, configurable TTL. |

---

## 12. Filter integration

RGC supports `oper` filters with operators such as:

```text
=, !=, <, >, <=, >=, like, not like, ilike, in, not in, between, null, not null, date, regexp
```

This is useful for administrative panels:

```http
GET /api/admin/federated-identities
```

Body or query:

```json
{
  "oper": {
    "and": [
      "provider|=|google",
      "provider_email_verified|=|true"
    ]
  },
  "relations": ["user:id,email,name"],
  "pagination": {
    "page": 1,
    "pageSize": 20
  }
}
```

Do not use dynamic filters for OAuth login.

OAuth callbacks must be explicit, controlled routes, not generic endpoints.

---

## 13. Field-level security integration

RGC has `FilterRequestByRole`, which can remove or reject fields a user is not allowed to modify.

This is very useful for business entities.

Example:

```php
protected array $fieldsByRole = [
    'Admin' => ['status_id', 'user_type'],
    'SuperAdmin' => ['is_superuser'],
];
```

But for federated identities it is recommended to be stricter:

```text
provider_user_id: never modifiable through CRUD
provider: never modifiable through CRUD
user_id: never modifiable through CRUD except by a controlled internal process
access_token: never visible
refresh_token: never visible
claims: visible only for admin auditing
```

An administrative model could declare:

```php
protected $hidden = [
    'access_token',
    'refresh_token',
];

protected array $fieldsByRole = [
    'SuperAdmin' => ['metadata'],
];
```

Even so, for sensitive changes prefer explicit services.

---

## 14. Recommended permissions for federated endpoints

Separate CRUD permissions from action permissions.

### Public login

Requires no permission:

```text
GET  /api/auth/federated/{provider}/redirect
GET  /api/auth/federated/{provider}/callback
POST /api/auth/federated/{provider}/token
```

But it must have:

```text
rate limit
state validation
provider enabled check
allowed user types
```

### Self-linking

Requires an authenticated user:

```text
POST /api/auth/federated/{provider}/link/token
DELETE /api/auth/federated/{provider}/unlink
```

Optional permission:

```text
profile.external-identities.manage
```

### Administration

Suggested permissions:

```text
security.federated-identities.index
security.federated-identities.show
security.federated-identities.unlink-any
security.federated-identities.audit
```

I do not recommend:

```text
security.federated-identities.create
security.federated-identities.update
```

Because creating/modifying links manually can open the door to account takeover.

---

## 15. How to detect RGC without coupling

Never do this in the `composer.json` of `laravel-federated-auth`:

```json
"ronu/rest-generic-class": "^x.y"
```

as a hard dependency.

Better:

```json
"suggest": {
  "ronu/rest-generic-class": "Optional integration for REST/permissions response enrichment"
}
```

And at runtime:

```php
$rgcAvailable = interface_exists(
    \Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles::class
);
```

This allows:

```text
Project A: federated-auth only
Project B: federated-auth + rest-generic-class
Project C: federated-auth + Spatie directly
Project D: federated-auth + custom permissions
```

---

## 16. Proposed optional-integration architecture

```text
laravel-federated-auth
│
├── Mandatory core
│   ├── FederatedAuthBroker
│   ├── ProviderAdapters
│   ├── IdentityLinkRepository
│   ├── UserResolver
│   ├── UserProvisioner
│   ├── RoleMapper
│   └── TokenIssuer
│
└── Optional integrations
    └── RestGenericClass
        ├── RestGenericPermissionPayloadResolver
        ├── RestGenericRoleMapper
        ├── RestGenericAuthResponseFormatter
        └── FederatedIdentity admin model example
```

Recommended namespace:

```php
Ronu\LaravelFederatedAuth\Integrations\RestGenericClass
```

Suggested classes:

```text
RestGenericClassDetector
RestGenericPermissionsResolver
RestGenericAuthResponseFormatter
RestGenericRoleMapper
```

---

## 17. Suggested contracts for `laravel-federated-auth`

### 17.1. PermissionPayloadResolverInterface

```php
interface PermissionPayloadResolverInterface
{
    public function resolve(Authenticatable $user, AuthContext $context): array;
}
```

Default:

```php
NullPermissionPayloadResolver
```

RGC:

```php
RestGenericPermissionPayloadResolver
```

### 17.2. AuthResponseFormatterInterface

```php
interface AuthResponseFormatterInterface
{
    public function format(AuthResult $result, AuthContext $context): array;
}
```

Default:

```php
DefaultAuthResponseFormatter
```

RGC style:

```php
RestGenericAuthResponseFormatter
```

### 17.3. IdentityAdminPresenterInterface

For administrative views:

```php
interface IdentityAdminPresenterInterface
{
    public function present(LinkedIdentity $identity): array;
}
```

This avoids accidentally exposing sensitive tokens or claims.

---

## 18. Ideal flow with both libraries

```text
1. Mobile app sends an Apple id_token.
2. laravel-federated-auth validates the Apple id_token.
3. ExternalIdentity(provider=apple, providerUserId=sub) is obtained.
4. The external link is looked up.
5. The local user is resolved.
6. UserStatusChecker validates the status.
7. RoleMapper syncs the Client role if applicable.
8. TokenIssuer issues a JWT.
9. If RGC is available:
      - PermissionPayloadResolver obtains the effective permissions.
      - AuthResponseFormatter builds a homogeneous response.
10. Frontend receives user + token + permissions.
```

Ideal response:

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 25,
      "email": "client@example.com",
      "user_type": "Client"
    },
    "auth": {
      "access_token": "jwt",
      "token_type": "bearer",
      "expires_in": 3600
    },
    "permissions": {
      "count": 3,
      "permissions": [
        {"name": "appointments.index", "module": "medical", "guard": "api"},
        {"name": "pets.create", "module": "clients", "guard": "api"}
      ]
    },
    "federated": {
      "provider": "apple",
      "was_provisioned": false,
      "was_linked": false
    }
  }
}
```

---

## 19. What must NOT be done

Do not:

```text
- Make laravel-federated-auth extend RestController.
- Use BaseService for OAuth callbacks.
- Expose federated_auth_identities with a full apiResource without restrictions.
- Allow generic update of provider_user_id.
- Store the provider access_token by default.
- Depend mandatorily on RGC in composer.
- Use dynamic filters for login processes.
- Map admin roles from Google/Facebook/Apple.
```

---

## 20. Recommended roadmap

### Phase 1 - Documentation

- Keep this guide.
- Add integration examples under `/examples/rest-generic-class`.

### Phase 2 - Optional contracts

Add to `laravel-federated-auth`:

```text
PermissionPayloadResolverInterface
AuthResponseFormatterInterface
```

Without mentioning RGC in the core.

### Phase 3 - Optional RGC adapter

Add classes under:

```text
src/Integrations/RestGenericClass
```

These classes must only activate if the RGC interfaces exist.

### Phase 4 - Administrative example

Create example:

```text
examples/rest-generic-class/FederatedIdentity.php
examples/rest-generic-class/FederatedIdentityService.php
examples/rest-generic-class/FederatedIdentityController.php
```

Only `index` and `show`.

### Phase 5 - Tests

- Test without RGC installed.
- Test with a fake ProvidesRoles.
- Test the default response formatter.
- Test the RGC-like response formatter.
- Test that external tokens are not exposed.

---

## 21. Architectural verdict

The integration makes a lot of sense, but not through inheritance or direct dependency.

The best architecture is:

```text
independent federated-auth core
+ small contracts
+ optional integration with rest-generic-class
+ documented examples
```

That keeps both libraries aligned with the same startup/framework style:

```text
explicit contracts
homogeneous responses
effective permissions
configurable models
secure by default
low coupling
```

The key sentence:

```text
RGC can enrich the authorization and administration experience,
but it must not be a requirement to authenticate.
```
