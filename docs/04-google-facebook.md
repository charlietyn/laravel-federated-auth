# 04 - Google and Facebook

Google and Facebook are Socialite-based providers.

The package now adds package-managed one-time `state` validation on top of Socialite so redirect callbacks can be rejected when they are missing, expired, reused or generated for another provider.

## Google variables

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback
```

## Facebook variables

```env
FEDERATED_AUTH_FACEBOOK_ENABLED=true
FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_REDIRECT_URI=https://api.example.com/api/auth/federated/facebook/callback
```

## Recommended redirect hardening

```env
FEDERATED_AUTH_OAUTH_STATE_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_TTL_SECONDS=300
FEDERATED_AUTH_OAUTH_STATE_BIND_USER_AGENT=true
FEDERATED_AUTH_OAUTH_STATE_BIND_IP=false
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com
```

## Redirect flow

```text
GET /api/auth/federated/google/redirect
```

The package:

1. creates a one-time state;
2. stores tenant/user type/channel metadata;
3. redirects the browser to Google or Facebook;
4. validates and consumes the same state on callback;
5. resolves/provisions the local user;
6. emits the local application token.

Google returns to:

```text
GET /api/auth/federated/google/callback
```

Facebook returns to:

```text
GET /api/auth/federated/facebook/callback
```

## Token flow for SPA/mobile

The mobile app or SPA obtains the provider token using the provider SDK.

Then it calls:

```http
POST /api/auth/federated/google/token
Content-Type: application/json

{
  "access_token": "provider-access-token",
  "user_type": "Client"
}
```

## Expected response

```json
{
  "success": true,
  "user": {
    "id": 123,
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 123
  },
  "token": "local-token",
  "token_type": "bearer",
  "was_provisioned": true,
  "was_linked": true
}
```

## Facebook caveat

Facebook may not return email. This can happen when:

- the user denies the email permission;
- the account has no public email;
- the app has not been approved for email permission;
- the account was created with a phone number only.

If `require_email=true`, the package rejects the login safely.

## Facebook email verification

The package no longer marks Facebook emails as verified by default.

To keep legacy behavior:

```php
'facebook' => [
    'trust_email_as_verified' => true,
]
```

Recommended default:

```php
'facebook' => [
    'trust_email_as_verified' => false,
    'require_verified_email' => false,
]
```

That means Facebook can authenticate the provider identity, while your application still treats email as contact data rather than a strong ownership claim.
