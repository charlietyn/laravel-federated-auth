# 04 - Google and Facebook

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

## Redirect flow

```text
GET /api/auth/federated/google/redirect
```

The package redirects the browser to Google.

Google returns to:

```text
GET /api/auth/federated/google/callback
```

The callback resolves or provisions a local user and emits the local token.

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
