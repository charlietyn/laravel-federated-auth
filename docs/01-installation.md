# 01 - Installation

## Step 1 - Install the package

```bash
composer require ronu/laravel-federated-auth
```

Line by line:

- `composer require` installs a PHP package into the current Laravel application.
- `ronu/laravel-federated-auth` is the Composer package name.
- Composer adds the package to `composer.json`.
- Laravel package auto-discovery registers `FederatedAuthServiceProvider`.

## Step 2 - Publish config

```bash
php artisan vendor:publish --tag=federated-auth-config
```

Line by line:

- `php artisan` runs Laravel's command console.
- `vendor:publish` copies package files into the host app.
- `--tag=federated-auth-config` publishes only `config/federated-auth.php`.

## Step 3 - Run the package migration

The recommended path runs **only** this package's migration, in isolation from
your application's own pending migrations:

```bash
php artisan federated-auth:migrate
```

This targets the migration shipped inside the package (via `--path`), so your
host-app migrations are left untouched. Useful flags:

| Flag | Effect |
|---|---|
| `--rollback` | Roll back the package migration |
| `--refresh` | Roll back and re-run the package migration |
| `--status` | Show the package migration status |
| `--database=` | Override the connection (defaults to `identity_store.connection`) |
| `--force` | Run in production without confirmation |
| `--pretend` | Print the SQL instead of executing it |

Prefer to own the schema yourself (for example to use a custom schema such as
`security.social_accounts`)? Publish the migration into your app and run the
standard migrate command instead:

```bash
php artisan vendor:publish --tag=federated-auth-migrations
php artisan migrate
```

## Step 4 - Configure provider credentials

Example for Google:

```env
FEDERATED_AUTH_ENABLED=true
FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback
```

## Step 5 - Configure local user behavior

For a standard Laravel app, set:

```env
FEDERATED_AUTH_USER_MODEL=App\\Models\\User
```

For complex apps, bind custom implementations of:

```text
UserProvisionerInterface
TokenIssuerInterface
UserResolverInterface
RoleMapperInterface
```
