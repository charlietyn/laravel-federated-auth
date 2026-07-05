# 06 - Kwikvet integration example

Kwikvet is a complex Laravel project:

- user model: `Modules\mod_security\Models\Users`;
- table: `mod_security.users`;
- JWT guard: `auth:api`;
- related profile tables: `Client`, `Veterinarian`, `Technician`;
- roles are assigned through `role_users`;
- only `Client` users should be auto-provisioned from Google/Facebook.

## User config

```php
'user' => [
    'model' => \Modules\mod_security\Models\Users::class,
    'connection' => 'db',
    'table' => 'mod_security.users',
    'primary_key' => 'id',
    'columns' => [
        'email' => 'email',
        'status' => 'status_id',
        'type' => 'user_type',
    ],
],
```

## Identity store

```php
'identity_store' => [
    'connection' => 'db',
    'table' => 'mod_security.social_accounts',
],
```

## Bindings

```php
'bindings' => [
    \Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => \App\Auth\KwikvetUserProvisioner::class,
    \Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface::class => \App\Auth\KwikvetJwtTokenIssuer::class,
],
```

## Why custom provisioning is required

A Kwikvet user is not only a row in `users`. For a Client login, provisioning must:

1. create `mod_security.users`;
2. create `mod_clients.client`;
3. assign role ID `4`;
4. set `status_id=1`;
5. generate a random internal password;
6. store or use provider avatar;
7. return a local JWT and refresh token.

## Never auto-provision

Do not auto-provision:

- Admin;
- Veterinarian;
- Technician.

These profiles need business validation.
