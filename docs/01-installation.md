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

## Step 3 - Publish migration

```bash
php artisan vendor:publish --tag=federated-auth-migrations
```

This publishes the identity-link table migration. You can use it directly, or create a custom migration if your application uses schemas such as `mod_security.social_accounts`.

## Step 4 - Run migration

```bash
php artisan migrate
```

## Step 5 - Configure provider credentials

Example for Google:

```env
FEDERATED_AUTH_ENABLED=true
FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback
```

## Step 6 - Configure local user behavior

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
