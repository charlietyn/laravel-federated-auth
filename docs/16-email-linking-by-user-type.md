# Email linking scoped by local user type

`allow_email_linking` allows a verified provider email to resolve an existing
local account when the external identity has not been linked before.

Applications that expose the same provider to several local account types can
restrict that behavior with `email_linking_allowed_user_types`:

```php
'providers' => [
    'google' => [
        'allow_email_linking' => true,
        'email_linking_allowed_user_types' => ['Admin'],
        'allowed_user_types' => ['Client', 'Admin'],
    ],
],
```

With this configuration:

- `user_type=Admin` may match an existing Admin by verified email;
- `user_type=Client` does not use automatic email linking;
- an empty or omitted `email_linking_allowed_user_types` preserves the original
  behavior and allows email linking for every provider-supported user type;
- malformed non-array values fail closed and disable email linking.

## Privileged accounts

Email linking and auto-provisioning are separate decisions. For administrative
accounts, keep the following protection enabled:

```php
'security' => [
    'prevent_admin_auto_provision' => true,
    'admin_user_types' => ['Admin', 'SuperAdmin', 'Administrator'],
    'deny_unverified_email_linking' => true,
],
```

This permits an existing Admin with a matching verified provider email to sign
in and become linked, while a provider identity with no matching local Admin is
rejected before the application's `UserProvisionerInterface` is called.

The resolver also applies the requested `user_type` when loading an already
linked local user. A Google identity linked to a Client therefore cannot be
reused to enter an Admin channel.
