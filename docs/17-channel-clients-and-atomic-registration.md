# Channel-bound OAuth clients and atomic registration

## Purpose

Applications with independent admin, site and mobile channels must not trust a
request parameter or header to select an OAuth client. This package supports a
`clients` map under a provider and resolves the entry from `AuthContext::channel`.
For browser callbacks the channel is restored from the one-time server-side
OAuth state.

```php
'providers' => [
    'google' => [
        'enabled' => true,
        'driver' => 'socialite',
        'socialite_driver' => 'google',
        'require_client_id' => true,
        'clients' => [
            'admin' => [
                'client_id' => env('GOOGLE_ADMIN_CLIENT_ID'),
                'client_secret' => env('GOOGLE_ADMIN_CLIENT_SECRET'),
                'redirect_uri' => env('GOOGLE_ADMIN_REDIRECT_URI'),
            ],
            'site' => [
                'client_id' => env('GOOGLE_SITE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_SITE_CLIENT_SECRET'),
                'redirect_uri' => env('GOOGLE_SITE_REDIRECT_URI'),
            ],
            'mobile' => [
                'client_id' => env('GOOGLE_MOBILE_CLIENT_ID'),
                'redirect_uri' => env('GOOGLE_MOBILE_REDIRECT_URI'),
            ],
        ],
    ],
],
```

When this map is present, a missing, unknown or disabled channel fails closed.
Metadata such as `requested_client_id` never changes the selected client.

## Browser security

The Socialite Google adapter now:

1. creates package-managed, one-time state;
2. stores the channel, user type, redirect URI, nonce and PKCE verifier;
3. sends `state`, `nonce`, `code_challenge` and `S256`;
4. restores the verifier from state on callback;
5. sends `code_verifier` during the authorization-code exchange;
6. requires a signed Google ID token;
7. verifies signature, issuer, audience and temporal JWT claims;
8. verifies the expected nonce;
9. confirms the ID-token subject matches the user-info subject.

Provider access and refresh tokens remain excluded from persistence unless the
host application explicitly enables token storage.

## Mobile security

A native application should normally submit its Google ID token to a host-owned
POST endpoint. The host constructs an `AuthContext` with a hard-coded trusted
`channel=mobile`; the channel-specific `client_id` is then used as the required
Google audience. A mobile client secret is not required or embedded in the app.

## Atomic registration

The package provides `AuthenticationTransactionInterface` and the default
`DatabaseAuthenticationTransaction`. `FederatedAuthBroker` runs the following
inside one host-configurable database transaction:

- local-user resolution or provisioning;
- federated identity creation/touch;
- host role/profile mapping;
- invitation consumption implemented by the host;
- local token issuance metadata preparation.

```php
'transactions' => [
    'enabled' => true,
    'connection' => 'db',
    'attempts' => 3,
],
```

The host may replace the transaction contract when its user and identity stores
use different persistence systems.

## Trusted multi-role expansion

`ConfigurableUserResolver` normally constrains resolution by `user_type`. A host
with one central user and additive roles may opt into cross-type resolution:

```php
'security' => [
    'allow_trusted_cross_user_type_resolution' => true,
],
```

The bypass is active only when `AuthContext::metadata` contains
`trusted_multi_role_registration=true`. Applications must set this marker only
while constructing a server-owned context and preserve it through package state.
`AuthContext::fromRequest` does not import arbitrary request metadata, so a
client cannot activate the bypass with query, body or headers.
