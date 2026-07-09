# 00 - Extensive junior guide: how to use `ronu/laravel-federated-auth`

> This guide is written for junior developers or learners who want to understand how federated login works in Laravel using Google, Facebook, Apple, Keycloak or another OIDC provider.
>
> The goal is not just to copy code. The goal is for you to understand what problem the library solves, what data is stored, what flow happens internally and how it connects to your own users, roles and permissions system.

---

## 1. What problem this library solves

Normally, when a Laravel application needs login with Google, Facebook or Apple, many developers do something like this:

```text
Google button -> Socialite -> find user by email -> create user -> login
```

That can work in small projects, but in real systems problems appear:

- your users table is not always called `users`;
- your model is not always `App\Models\User`;
- the email is not always unique;
- the same email may exist as a client, veterinarian, technician or administrator;
- Google, Facebook and Apple do not return the same data;
- Apple may return a private relay email;
- Facebook sometimes does not return an email;
- your API may use JWT, Sanctum, session cookies or another system;
- a local user may have several external identities linked;
- not every user should be allowed to self-register;
- an administrator must not be created automatically just because someone logged in with Google.

This library organizes that problem using a responsibility-based architecture:

```text
External provider
    -> Adapter
    -> ExternalIdentity
    -> Broker
    -> Resolver/Provisioner
    -> Identity Link
    -> Role Mapper
    -> Token Issuer
```

In simple words:

```text
The provider states who the person is.
Your application decides who they are locally and what permissions they have.
```

---

## 2. Basic concepts before touching code

### 2.1. Authentication

Authentication means answering:

```text
Who are you?
```

Example:

```text
I am carlos@example.com and Google confirms this account exists.
```

### 2.2. Authorization

Authorization means answering:

```text
What can you do?
```

Example:

```text
You can create appointments, but you cannot manage users.
```

### 2.3. OAuth2

OAuth2 is primarily an authorization protocol. It lets an application access resources of another system with the user's permission.

Example:

```text
Allow my app to read your Google email.
```

### 2.4. OpenID Connect / OIDC

OIDC is a layer on top of OAuth2 used for authentication.

OIDC lets you obtain an `id_token`, which usually contains claims such as:

```json
{
  "sub": "1234567890",
  "email": "client@example.com",
  "email_verified": true,
  "name": "Client Example"
}
```

### 2.5. Provider user id / `sub`

This is the real identifier of the user at the provider.

For Google, Apple, Keycloak or OIDC it usually comes as:

```text
sub
```

For Facebook it may come as:

```text
id
```

The golden rule is:

```text
Do not use the email as the primary identity.
Use provider + provider_user_id.
```

Example:

```text
google + 107691503500061507151
apple + 000123.abc456def789
facebook + 123456789
```

---

## 3. What your local system stores

The external provider does not replace your local database.

Your application must still keep a local users table, for example:

```text
users
```

Or in a modular system:

```text
security.users
```

The library creates or uses an external links table:

```text
federated_auth_identities
```

That table answers:

```text
Which local user does this Google/Facebook/Apple account correspond to?
```

Example:

| id | user_id | provider | provider_user_id | provider_email |
|---:|---:|---|---|---|
| 1 | 25 | google | 107691503500061507151 | client@example.com |
| 2 | 25 | apple | 000123.abc456def789 | private@privaterelay.appleid.com |

In this example, local user `25` can sign in with either Google or Apple.

---

## 4. Basic installation

```bash
composer require ronu/laravel-federated-auth
php artisan vendor:publish --tag=federated-auth-config
php artisan federated-auth:migrate
```

After publishing the configuration, review:

```text
config/federated-auth.php
```

That is where you define:

- enabled providers;
- routes;
- the local user model;
- user columns;
- the external links table;
- security rules;
- custom contracts.

---

## 5. Minimal configuration for a normal Laravel project

Assume a project with:

```text
App\Models\User
users.id
users.name
users.email
users.password
```

Configure:

```php
'user' => [
    'model' => App\Models\User::class,
    'primary_key' => 'id',
    'columns' => [
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
        'password' => 'password',
        'status' => null,
        'type' => null,
    ],
],
```

If your application has no user statuses, you can leave `status` as `null`.

If your application does not differentiate user types, you can leave `type` as `null`.

---

## 6. Configuration for a modular system

Assume:

```text
Modules\security\Models\Users
security.users
status_id
user_type
```

Configure:

```php
'user' => [
    'model' => Modules\security\Models\Users::class,
    'connection' => 'pgsql',
    'table' => 'security.users',
    'primary_key' => 'id',
    'columns' => [
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
        'username' => 'username',
        'password' => 'password',
        'avatar' => 'avatar',
        'status' => 'status_id',
        'type' => 'user_type',
    ],
    'active_status_values' => [1, '1', true, 'active', 'enabled'],
],
```

And the external links table can live in your security schema:

```php
'identity_store' => [
    'connection' => 'pgsql',
    'table' => 'security.social_accounts',
    'tenant_column' => 'tenant_id',
    'user_id_column' => 'user_id',
    'store_provider_tokens' => false,
    'encrypt_provider_tokens' => true,
],
```

---

## 7. Web login flow with Google

### 7.1. The user presses the button

Frontend:

```html
<a href="https://api.example.com/api/auth/federated/google/redirect">
    Continue with Google
</a>
```

### 7.2. Laravel receives the request

```http
GET /api/auth/federated/google/redirect
```

The library:

1. validates that Google is enabled;
2. creates a one-time `state`;
3. optionally generates a `nonce`;
4. stores metadata such as IP, user-agent, tenant, channel;
5. redirects to Google.

### 7.3. Google authenticates the user

Google shows its screen:

```text
Choose an account
Allow access to profile and email
```

### 7.4. Google returns to the callback

```http
GET /api/auth/federated/google/callback?code=abc&state=xyz
```

The library:

1. consumes the `state`;
2. rejects it if the state does not exist, expired or was already used;
3. fetches the user data from Google;
4. builds an `ExternalIdentity`;
5. checks whether a linked account already exists;
6. if it does not exist, provisions a user if allowed;
7. creates the external link;
8. issues your local token.

### 7.5. Expected response

```json
{
  "success": true,
  "user": {
    "id": 25,
    "name": "Client Example",
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 25
  },
  "token": "jwt-local-token",
  "access_token": "jwt-local-token",
  "token_type": "bearer",
  "was_provisioned": true,
  "was_linked": true,
  "metadata": []
}
```

---

## 8. Login flow for mobile or SPA

On mobile, the backend redirect is often not used. The native SDK is used instead.

Example:

```text
React Native App -> Google SDK -> access_token -> Laravel API
```

The app calls:

```http
POST /api/auth/federated/google/token
Content-Type: application/json

{
  "access_token": "provider-access-token",
  "user_type": "Client",
  "channel": "mobile"
}
```

Laravel validates the token with the provider and then runs the same local flow.

For Apple mobile, an `id_token` is normally sent:

```http
POST /api/auth/federated/apple/token
Content-Type: application/json

{
  "id_token": "apple-identity-token",
  "user_type": "Client",
  "channel": "mobile"
}
```

---

## 9. What `ExternalIdentity` is

`ExternalIdentity` is a normalized version of the data returned by each provider.

Google, Facebook, Apple and Keycloak return different structures. The library converts them into a common structure:

```text
provider
providerUserId
email
emailVerified
name
firstName
lastName
avatarUrl
claims
groups
roles
accessToken
refreshToken
expiresIn
```

Example with Google:

```php
new ExternalIdentity(
    provider: 'google',
    providerUserId: '107691503500061507151',
    email: 'client@example.com',
    emailVerified: true,
    name: 'Client Example',
    avatarUrl: 'https://lh3.googleusercontent.com/...'
);
```

Example with Apple:

```php
new ExternalIdentity(
    provider: 'apple',
    providerUserId: '000123.abc456def789',
    email: 'private@privaterelay.appleid.com',
    emailVerified: true,
    name: null
);
```

---

## 10. What `AuthContext` is

`AuthContext` carries information about the current request.

Example:

```text
provider = google
tenantId = clinic-1
userType = Client
channel = mobile
guard = api
redirectUri = https://api.example.com/callback
state = xyz
metadata = ip + user_agent
```

This lets the same library work in different scenarios:

```text
admin panel
mobile app
client portal
multi-tenant API
enterprise login
```

---

## 11. What `FederatedAuthBroker` is

The broker is the main coordinator.

It does not talk directly to Google. It does not create users on its own. It does not decide final permissions by itself.

It coordinates the pieces:

```text
Provider Adapter
IdentityLinkRepository
UserResolver
UserProvisioner
UserStatusChecker
RoleMapper
TokenIssuer
```

Think of it as an orchestra conductor.

---

## 12. Automatic registration: `UserProvisionerInterface`

When a user signs in for the first time and no external link exists, two things can happen:

### Case A: auto-provision disabled

```text
No local user exists -> deny login
```

This is recommended for administrators, technicians, veterinarians or enterprise users.

### Case B: auto-provision enabled

```text
No local user exists -> create local user -> create profile -> assign role -> create link -> login
```

For that you must implement:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

final class ClientUserProvisioner implements UserProvisionerInterface
{
    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable
    {
        return DB::transaction(function () use ($identity, $context) {
            $user = User::query()->create([
                'name' => $identity->name ?: 'New Client',
                'email' => $identity->email,
                'password' => Hash::make(Str::random(40)),
                'status_id' => 1,
                'user_type' => $context->userType ?: 'Client',
                'avatar' => $identity->avatarUrl,
            ]);

            Client::query()->create([
                'user_id' => $user->id,
                'profile_completed' => false,
            ]);

            $user->assignRole('Client');

            return $user;
        });
    }
}
```

Then you register it in config:

```php
'bindings' => [
    Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => App\Auth\ClientUserProvisioner::class,
],
```

---

## 13. Resolving existing users: `UserResolverInterface`

The resolver looks up local users.

The library ships a configurable resolver that can look up:

```text
by id
by email
by email + user_type
```

But in complex systems you may need your own.

Example:

```php
final class TenantAwareUserResolver implements UserResolverInterface
{
    public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable
    {
        return User::query()
            ->where('id', $userId)
            ->where('tenant_id', $context->tenantId)
            ->first();
    }

    public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return User::query()
            ->where('email', $identity->email)
            ->where('tenant_id', $context->tenantId)
            ->where('user_type', $context->userType)
            ->first();
    }
}
```

---

## 14. Issuing the local token: `TokenIssuerInterface`

The external provider must not be the final token of your app.

Correct flow:

```text
Google token -> validate identity -> issue local token for my API
```

The library lets you issue:

- JWT;
- Sanctum token;
- session cookie;
- custom token.

JWT example:

```php
final class ApiJwtTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        $token = auth('api')->login($user);

        return new AuthResult($user, [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL(),
        ]);
    }
}
```

---

## 15. Roles and permissions: where they connect

Do not confuse federated login with permissions.

The provider says:

```text
This person is Google account X.
```

Your system says:

```text
This local person is a Client and can create appointments.
```

### Recommended rule

```text
Google/Facebook/Apple -> only auto-provision Client.
Admin/Veterinarian/Technician -> require internal validation.
Keycloak/OIDC enterprise -> may map external roles if you trust that IdP.
```

Example `RoleMapperInterface`:

```php
final class AppRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        if ($context->userType === 'Client') {
            $user->syncRoles(['Client']);
            return;
        }

        if ($identity->provider === 'keycloak') {
            if (in_array('clinic-admin', $identity->roles, true)) {
                $user->syncRoles(['Admin']);
            }
        }
    }
}
```

---

## 16. Linking another external account

An already authenticated user can link Google, Facebook or Apple.

Example:

```http
POST /api/auth/federated/google/link/token
Authorization: Bearer local-jwt
Content-Type: application/json

{
  "access_token": "google-provider-token"
}
```

The library validates:

1. that the local user is authenticated;
2. that the external token is valid;
3. that the external identity does not belong to another user;
4. that the link can be created or updated.

---

## 17. Unlinking a provider

```http
DELETE /api/auth/federated/google/unlink
Authorization: Bearer local-jwt
```

The library prevents a common mistake:

```text
If the user has no local password and only has one external identity,
it does not allow removing the last identity.
```

That avoids leaving the user with no way to sign in.

---

## 18. Google

Config:

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback
```

Recommended:

```php
'google' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => true,
    'allow_email_linking' => false,
    'allowed_user_types' => ['Client'],
]
```

Use Google for client login, not to create administrators automatically.

---

## 19. Facebook

Config:

```env
FEDERATED_AUTH_FACEBOOK_ENABLED=true
FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_REDIRECT_URI=https://api.example.com/api/auth/federated/facebook/callback
```

Facebook may not return an email.

Recommended:

```php
'facebook' => [
    'require_email' => true,
    'require_verified_email' => false,
    'trust_email_as_verified' => false,
    'auto_provision' => true,
    'allow_email_linking' => false,
    'allowed_user_types' => ['Client'],
]
```

Do not assume that the Facebook email is always strong or verified.

---

## 20. Apple

Config:

```env
FEDERATED_AUTH_APPLE_ENABLED=true
APPLE_CLIENT_ID=com.example.web
APPLE_TEAM_ID=TEAMID1234
APPLE_KEY_ID=ABC123DEFG
APPLE_PRIVATE_KEY_PATH=/secure/path/AuthKey_ABC123DEFG.p8
APPLE_REDIRECT_URI=https://api.example.com/api/auth/federated/apple/callback
```

Apple uses a special `client_secret`, which is a JWT signed with your `.p8` private key.

The library can generate it if you configure:

```text
APPLE_TEAM_ID
APPLE_KEY_ID
APPLE_CLIENT_ID
APPLE_PRIVATE_KEY_PATH
```

Apple may return a private relay email:

```text
abc123@privaterelay.appleid.com
```

That is normal. Store it as a contact email, but do not use it as the primary identity.

The primary identity is still:

```text
apple + sub
```

---

## 21. Keycloak / enterprise OIDC

Keycloak is normally used in enterprises.

Example config:

```env
FEDERATED_AUTH_KEYCLOAK_ENABLED=true
KEYCLOAK_BASE_URL=https://auth.example.com
KEYCLOAK_REALM=ronu
KEYCLOAK_CLIENT_ID=ronu-api
KEYCLOAK_CLIENT_SECRET=secret
KEYCLOAK_REDIRECT_URI=https://api.example.com/api/auth/federated/keycloak/callback
```

Recommended:

```php
'keycloak' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => false,
    'allow_email_linking' => false,
    'sync_roles' => true,
]
```

For Keycloak, you usually do not want to create privileged users automatically. You want them to exist locally first or go through an internal validation.

---

## 22. Security explained for juniors

### 22.1. Why not to trust email

Bad:

```php
$user = User::where('email', $providerEmail)->first();
```

Problems:

- the email can change;
- the email may not be verified;
- several user types may share an email;
- Apple may use a relay email;
- Facebook may not provide an email;
- there may be an account takeover risk if linking happens automatically.

Good:

```text
Look up by provider + provider_user_id.
```

### 22.2. What `state` is

`state` prevents someone from forging a fake callback.

Flow:

```text
Laravel creates state ABC
Provider returns state ABC
Laravel consumes ABC only once
```

If someone tries to reuse it:

```text
rejected
```

### 22.3. What `nonce` is

`nonce` protects the `id_token` in OIDC flows.

Flow:

```text
Laravel creates nonce N1
Provider embeds N1 inside the id_token
Laravel validates that the id_token contains N1
```

### 22.4. What PKCE is

PKCE prevents a stolen authorization code from being exchanged without the correct `code_verifier`.

Flow:

```text
Laravel creates a secret code_verifier
Laravel sends a public code_challenge
Provider returns a code
Laravel exchanges code + code_verifier
```

---

## 23. Recommended secure configuration

```env
FEDERATED_AUTH_ENABLED=true
FEDERATED_AUTH_ROUTES_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_TTL_SECONDS=300
FEDERATED_AUTH_OAUTH_STATE_BIND_USER_AGENT=true
FEDERATED_AUTH_OAUTH_STATE_BIND_IP=false
FEDERATED_AUTH_PKCE_ENABLED=true
FEDERATED_AUTH_OIDC_NONCE_ENABLED=true
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com,app.example.com
FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS=false
FEDERATED_AUTH_STORE_PROVIDER_TOKENS=false
FEDERATED_AUTH_ENCRYPT_PROVIDER_TOKENS=true
```

For local development:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=localhost,127.0.0.1
FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS=true
```

---

## 24. Complete first-time flow example

A new user signs in with Google.

```text
1. Frontend opens /google/redirect
2. Laravel creates state
3. Google authenticates
4. Google returns to the callback
5. Laravel validates the state
6. Laravel obtains the ExternalIdentity
7. Looks up federated_auth_identities by google + provider_user_id
8. It does not exist
9. Since auto_provision=true, it calls ClientUserProvisioner
10. Creates users
11. Creates clients
12. Assigns the Client role
13. Creates federated_auth_identities
14. Issues a local JWT
15. Returns the response to the frontend
```

---

## 25. Complete existing-user flow example

```text
1. The user signs in with Apple
2. Laravel validates the id_token
3. Extracts the sub
4. Looks up apple + sub in federated_auth_identities
5. Finds user_id=25
6. Loads local user 25
7. Verifies active status
8. Updates last_login_at
9. Issues a local JWT
```

It does not create a new user.

---

## 26. Complete blocked-user flow example

```text
1. The user authenticates successfully with Google
2. Google confirms the identity
3. Laravel finds the local user
4. The local user has status_id=0
5. Laravel rejects the login
```

Important:

```text
Google saying you are you does not mean your local system must grant you access.
```

---

## 27. Duplicate-email error example

Assume:

| id | email | user_type |
|---:|---|---|
| 1 | carlos@example.com | Client |
| 2 | carlos@example.com | Veterinarian |

If you allow linking by email without `user_type`, the system does not know which user to link.

For safety, it must reject:

```text
AmbiguousLocalUserException
```

Solutions:

- require `user_type`;
- do not allow `allow_email_linking`;
- do manual linking from an authenticated account;
- use an internal verification flow.

---

## 28. Common errors and solutions

### Error: provider disabled

Cause:

```php
'enabled' => false
```

Solution:

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
```

### Error: email required

Cause:

```php
'require_email' => true
```

but the provider did not return an email.

Solution:

- check the scopes;
- check the app permissions;
- on Facebook, verify the `email` permission;
- decide whether your business allows login without an email.

### Error: email not verified

Cause:

```php
'require_verified_email' => true
```

but the provider did not confirm verification.

Solution:

- keep it that way for Google/Apple;
- on Facebook, use `require_verified_email=false` and treat the email as a contact.

### Error: state missing

Cause:

- incorrect callback;
- the provider did not return a `state`;
- the frontend called the callback manually;
- the cache was cleared;
- the TTL expired.

Solution:

- check the configured redirect URI;
- check the cache driver;
- make sure the redirect session is not lost;
- increase the TTL if login takes too long.

### Error: redirect host not allowed

Cause:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com
```

and you are using:

```text
https://staging-api.example.com
```

Solution:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com,staging-api.example.com
```

---

## 29. Pre-production checklist

- [ ] Google configured with a real redirect URI.
- [ ] Facebook configured and email permission reviewed.
- [ ] Apple Services ID configured.
- [ ] Apple `.p8` stored outside the repo.
- [ ] `FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS` configured.
- [ ] `FEDERATED_AUTH_OAUTH_STATE_ENABLED=true`.
- [ ] `FEDERATED_AUTH_PKCE_ENABLED=true`.
- [ ] `FEDERATED_AUTH_OIDC_NONCE_ENABLED=true`.
- [ ] `store_provider_tokens=false` unless there is a real need.
- [ ] Admin is not auto-provisioned.
- [ ] Veterinarian is not auto-provisioned.
- [ ] Technician is not auto-provisioned.
- [ ] Client has its own provisioner.
- [ ] RoleMapper tested.
- [ ] TokenIssuer tested.
- [ ] Tests pass.
- [ ] Logs do not print provider tokens.
- [ ] The API response does not expose password or internal columns.

---

## 30. Final mental architecture

Remember this diagram:

```text
[Google/Facebook/Apple/Keycloak]
              |
              v
       Provider Adapter
              |
              v
       ExternalIdentity
              |
              v
      FederatedAuthBroker
              |
    +---------+---------+---------+---------+
    |         |         |         |         |
Resolver  Provisioner  LinkRepo  RoleMap  TokenIssuer
    |         |         |         |         |
    v         v         v         v         v
 Local     Create      External   Roles    JWT/Sanctum
 user      user        link       perms    session
```

The most important sentence:

```text
The external identity authenticates. Your local system authorizes.
```

If you understand that, you can use this library in a safe and professional way.
