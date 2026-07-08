# Ronu example

Copy `RonuUserProvisioner.php` and `RonuJwtTokenIssuer.php` into `app/Auth` of the Ronu backend.

Then bind them in `config/federated-auth.php`:

```php
'bindings' => [
    \Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => \App\Auth\RonuUserProvisioner::class,
    \Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface::class => \App\Auth\RonuJwtTokenIssuer::class,
],
```

Set the user model:

```php
'user' => [
    'model' => \Modules\security\Models\Users::class,
    'connection' => 'db',
    'table' => 'security.users',
    'primary_key' => 'id',
],
```

Set the identity store table:

```php
'identity_store' => [
    'connection' => 'db',
    'table' => 'security.social_accounts',
],
```
