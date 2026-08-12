# 06 - AppProject integration example

`AppProject` is the fictional host application used throughout this
documentation (see `docs/README.md` → *Naming in these docs*). It stands in for
a complex Laravel project:

- user model: `Modules\security\Models\Users`;
- table: `security.users`;
- JWT guard: `auth:api`;
- related profile tables: `Client`, `Veterinarian`, `Technician`;
- roles are assigned through `role_users`;
- only `Client` users should be auto-provisioned from Google/Facebook.

## User config

```php
'user' => [
    'model' => \Modules\security\Models\Users::class,
    'connection' => 'db',
    'table' => 'security.users',
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
    'table' => 'security.social_accounts',
],
```

## Bindings

```php
'bindings' => [
    \Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => \App\Auth\AppProjectUserProvisioner::class,
    \Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface::class => \App\Auth\AppProjectJwtTokenIssuer::class,
],
```

## Why custom provisioning is required

An AppProject user is not only a row in `users`. For a Client login, provisioning must:

1. create `security.users`;
2. create `clients.client`;
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
