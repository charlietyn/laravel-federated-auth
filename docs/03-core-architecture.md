# 03 - Core architecture

## Main flow

```text
External Provider
   |
   v
Provider Adapter
   |
   v
ExternalIdentity DTO
   |
   v
FederatedAuthBroker
   |
   +--> IdentityLinkRepository
   +--> UserResolver
   +--> UserProvisioner
   +--> UserStatusChecker
   +--> RoleMapper
   +--> TokenIssuer
```

## Why the package uses contracts

The package cannot assume:

- your user table name;
- your user model;
- your database schema;
- your database engine;
- your token system;
- your role system;
- whether users can be auto-created;
- whether emails are unique.

Therefore the package provides the orchestration, but your application provides business rules.

## Important DTOs

### AuthContext

Carries request-specific information:

```text
provider
guard
tenantId
userType
channel
redirectUri
state
metadata
```

### ExternalIdentity

Normalizes provider data:

```text
provider
providerUserId
email
emailVerified
name
avatarUrl
claims
groups
roles
```

### AuthResult

Returns final local authentication result:

```text
user
tokens
externalIdentity
wasProvisioned
wasLinked
metadata
```

## Services

### FederatedAuthBroker

Coordinates the whole login flow.

### Provider adapters

Talk to Google, Facebook, Keycloak or generic OIDC providers.

### IdentityLinkRepository

Stores provider identity to local user mapping.

### UserProvisioner

Creates local users only when allowed.

### TokenIssuer

Emits the final local application token.
