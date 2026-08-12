# AppProject example

`AppProject` is the fictional host application used throughout this
documentation — see `docs/README.md` → *Naming in these docs*. Substitute your
own application's names when you copy any of this.

Copy `AppProjectUserProvisioner.php` and `AppProjectJwtTokenIssuer.php` into
`app/Auth` of your backend.

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

See also `ErrorLogReporter.php` in this directory for persisting broker
failures to a host error table (`docs/18-error-reporting.md`).
