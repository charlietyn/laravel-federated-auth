# 13 - Sign in with Apple

Apple is implemented as an OIDC-style provider with a dedicated adapter because its `client_secret` is not a static shared secret. It is a JWT signed with the Apple private key.

## Environment

```env
FEDERATED_AUTH_APPLE_ENABLED=true
APPLE_CLIENT_ID=com.example.web
APPLE_TEAM_ID=TEAMID1234
APPLE_KEY_ID=ABC123DEFG
APPLE_PRIVATE_KEY_PATH=/secure/path/AuthKey_ABC123DEFG.p8
APPLE_REDIRECT_URI=https://api.example.com/api/auth/federated/apple/callback
```

Alternatively, provide a raw private key through `APPLE_PRIVATE_KEY` using escaped newlines.

```env
APPLE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
```

## Web redirect flow

```text
GET /api/auth/federated/apple/redirect
```

The adapter creates a one-time state, nonce and PKCE verifier/challenge, then redirects to:

```text
https://appleid.apple.com/auth/authorize
```

Apple returns to:

```text
POST or GET /api/auth/federated/apple/callback
```

The callback flow:

1. consumes and validates `state`;
2. exchanges `code` for tokens;
3. generates the Apple `client_secret` JWT when needed;
4. validates the returned `id_token` against Apple JWKS;
5. validates `iss`, `aud`, `azp` when required and `nonce` when present;
6. normalizes the user into `ExternalIdentity`;
7. resolves/provisions the local user;
8. creates the identity link;
9. emits the local application token.

## Mobile token flow

Native Apple SDKs commonly return an identity token. Send it as `id_token`:

```http
POST /api/auth/federated/apple/token
Content-Type: application/json

{
  "id_token": "apple-identity-token",
  "user_type": "Client"
}
```

The package treats this value as an Apple `id_token`, validates it and then runs the same local authentication pipeline.

## What gets stored

The local identity link stores:

```text
tenant_id + provider + provider_user_id
```

For Apple, `provider_user_id` is the `sub` claim.

Recommended stored fields:

```text
provider = apple
provider_user_id = id_token.sub
provider_email = id_token.email
provider_email_verified = id_token.email_verified
claims = minimal Apple claims
last_login_at = current timestamp
```

## Important Apple behavior

Apple may return a private relay email instead of the user's real email. Treat it as contact information only. Do not use the email as the primary federated identity key.

The correct identity key is:

```text
provider + provider_user_id
```

## Recommended settings

```php
'apple' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => true,
    'allow_email_linking' => false,
    'allowed_user_types' => ['Client'],
]
```

Do not auto-provision Admin, Veterinarian or Technician users through Apple login unless a separate governance process explicitly approves that user.
