# ronu/laravel-federated-auth

Configurable Laravel federated authentication bridge for Google, Facebook, Keycloak and generic OIDC providers.

The package does not assume your users table is called `users`, your model is `App\Models\User`, your email is unique, or your application uses a specific token system.

## Quick install

```bash
composer require ronu/laravel-federated-auth
php artisan vendor:publish --tag=federated-auth-config
php artisan vendor:publish --tag=federated-auth-migrations
php artisan migrate
```

Read the full documentation in [`docs`](docs).
