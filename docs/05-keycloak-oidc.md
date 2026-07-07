# 05 - Keycloak and generic OIDC

## Keycloak environment

```env
FEDERATED_AUTH_KEYCLOAK_ENABLED=true
KEYCLOAK_BASE_URL=https://auth.example.com
KEYCLOAK_REALM=kwikvet
KEYCLOAK_CLIENT_ID=kwikvet-api
KEYCLOAK_CLIENT_SECRET=secret
KEYCLOAK_REDIRECT_URI=https://api.example.com/api/auth/federated/keycloak/callback
```

The package derives:

```text
/realms/{realm}/protocol/openid-connect/auth
/realms/{realm}/protocol/openid-connect/token
/realms/{realm}/protocol/openid-connect/userinfo
/realms/{realm}/protocol/openid-connect/certs
```

## Keycloak identity

Keycloak can return:

```text
sub
email
email_verified
preferred_username
realm_access.roles
groups
```

The package normalizes these values into `ExternalIdentity`.

## Native/mobile token flow

Native clients may submit either an access token or an ID token:

```http
POST /api/auth/federated/keycloak/token
Content-Type: application/json

{
  "id_token": "keycloak-id-token",
  "user_type": "Client"
}
```

or:

```http
POST /api/auth/federated/keycloak/token
Content-Type: application/json

{
  "access_token": "keycloak-access-token",
  "user_type": "Client"
}
```

The package preserves which field was submitted:

| Submitted field | OIDC behavior |
|---|---|
| `id_token` | Validate through JWKS and decode as an ID token, even if `userinfo_endpoint` is configured. |
| `access_token` | Call `userinfo_endpoint` when configured and treat the token as a bearer access token. |
| raw token without explicit type | Fallback: JWT-looking tokens are treated as ID tokens; otherwise userinfo is used when available. |

This avoids sending a native-client `id_token` to the `userinfo_endpoint` as a bearer access token.

## Role mapping

Create a custom `RoleMapperInterface` implementation to map Keycloak roles/groups to local roles.

```php
class KeycloakRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        if (in_array('kwikvet-admin', $identity->roles, true)) {
            $user->assignRole('Admin');
        }
    }
}
```

## Recommended Keycloak security

```php
'auto_provision' => false,
'allow_email_linking' => false,
'require_email' => true,
'require_verified_email' => true,
```

Keycloak is often used for enterprise access. Do not auto-create privileged users unless your governance process explicitly allows it.

## Generic OIDC

For Auth0, Azure AD, Okta or a custom OIDC server, configure a provider with driver `oidc` and set authorization, token, userinfo and JWKS endpoints.
