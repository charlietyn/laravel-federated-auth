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

Admin users must not be auto-created from Google/Facebook/Apple/Keycloak unless your governance process explicitly allows it.

## Redirect-flow state

When `security.oauth_state.enabled=true`, redirect flows use a one-time state stored in cache.

The callback is rejected when:

- `state` is missing;
- `state` is expired;
- `state` was already consumed;
- `state` belongs to another provider;
- fingerprint binding fails.

This protects the application callback endpoint from forged or replayed redirect responses.

## PKCE

Generic OIDC, Keycloak and Apple redirect flows use PKCE when enabled.

The adapter sends:

```text
code_challenge
code_challenge_method=S256
```

and later exchanges the authorization code with:

```text
code_verifier
```

## OIDC nonce

OIDC-capable redirect flows generate a nonce. When an `id_token` is returned, the adapter validates the token `nonce` against the stored authorization transaction.

## OIDC token validation

The generic OIDC adapter validates:

- JWT signature through JWKS;
- `iss` when configured;
- `aud` against `client_id`;
- `azp` when multiple audiences exist;
- `nonce` when a redirect transaction has one.

## Provider token storage

Default: do not store provider access tokens.

If you must store them:

```php
'store_provider_tokens' => true,
'encrypt_provider_tokens' => true,
```

## Last identity unlink

If a user has no password and only one external identity, unlinking it can lock the account. The package denies this by default.

## Safe API response

Do not expose the full Eloquent user model by default. Configure the response fields:

```php
'response' => [
    'include_user' => true,
    'user_fields' => ['id', 'uuid', 'name', 'email', 'user_type', 'status_id'],
]
```

## Scenario matrix

| Scenario | Expected behavior |
|---|---|
| Google verified email, no local user | Provision if allowed |
| Google unverified email | Deny if verified required |
| Facebook no email | Deny if email required |
| Facebook email present | Treat as contact data unless explicitly trusted |
| Apple private relay email | Store as provider email, not identity key |
| Existing provider identity | Login local user |
| Same email many users | Deny ambiguous link |
| Local user disabled | Deny login |
| Admin requested by social login | Deny auto-provision |
| Keycloak role removed | Role mapper decides |
| Tenant mismatch | Isolate or deny |
| Callback without state | Deny |
| Replayed state | Deny |
| OIDC nonce mismatch | Deny |
| OIDC audience mismatch | Deny |
