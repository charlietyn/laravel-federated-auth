# 07 - Extending contracts

## UserProvisionerInterface

Use this to create a local user.

```php
public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable;
```

Typical responsibilities:

- create user row;
- create domain profile;
- assign role;
- set status;
- save tenant;
- mark profile incomplete.

## TokenIssuerInterface

Use this to return your application's token.

```php
public function issue(Authenticatable $user, AuthContext $context): AuthResult;
```

Implementations can emit:

- JWT;
- Sanctum token;
- session cookie;
- custom token.

## UserResolverInterface

Use this when default model lookup is not enough.

Examples:

- multi-tenant lookup;
- user table is not Eloquent;
- SQL Server schema has custom columns;
- users are stored in MongoDB.

## RoleMapperInterface

Use this to map Keycloak roles or OIDC groups.

## IdentityLinkRepositoryInterface

Use this when identity links are stored somewhere else:

- MongoDB;
- external identity service;
- tenant database;
- different schema per app.
