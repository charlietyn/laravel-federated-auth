# 08 - Security and edge cases

## Do not trust email as identity

The safe identity key is:

```text
provider + provider_user_id
```

For multi-tenant apps:

```text
tenant_id + provider + provider_user_id
```

## Missing email

If the provider does not return email and `require_email=true`, the package throws `EmailRequiredException`.

## Unverified email

If `require_verified_email=true` and the provider returns an unverified email, the package throws `EmailNotVerifiedException`.

## Ambiguous local user

If multiple local users share the same email, automatic email linking must be denied.

## Disabled local user

External login success does not mean local access is allowed. The local user status still controls access.

## Admin auto-provisioning

Admin users must not be auto-created from Google/Facebook/Keycloak.

## Provider token storage

Default: do not store provider access tokens.

If you must store them:

```php
'store_provider_tokens' => true,
'encrypt_provider_tokens' => true,
```

## Last identity unlink

If a user has no password and only one external identity, unlinking it can lock the account. The package denies this by default.

## Scenario matrix

| Scenario | Expected behavior |
|---|---|
| Google verified email, no local user | Provision if allowed |
| Google unverified email | Deny if verified required |
| Facebook no email | Deny if email required |
| Existing provider identity | Login local user |
| Same email many users | Deny ambiguous link |
| Local user disabled | Deny login |
| Admin requested by social login | Deny auto-provision |
| Keycloak role removed | Role mapper decides |
| Tenant mismatch | Isolate or deny |
