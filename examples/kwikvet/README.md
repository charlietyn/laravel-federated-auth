# AppProject example

Copy `AppProjectUserProvisioner.php` and `AppProjectJwtTokenIssuer.php` into `app/Auth` of the AppProject backend.

Then bind them in `config/federated-auth.php`:

```php
'bindings' => [
    \Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => \App\Auth\AppProjectUserProvisioner::class,
    \Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface::class => \App\Auth\AppProjectJwtTokenIssuer::class,
],
```

Set the user model:

```php
'user' => [
    'model' => \Modules\mod_security\Models\Users::class,
    'connection' => 'db',
    'table' => 'mod_security.users',
    'primary_key' => 'id',
],
```

Set the identity store table:

```php
'identity_store' => [
    'connection' => 'db',
    'table' => 'mod_security.social_accounts',
],
```
