# 09 - Testing

## Package tests

```bash
vendor/bin/phpunit
```

## Application tests to create

- provider disabled rejects login;
- provider enabled accepts login;
- first login creates identity link;
- second login reuses identity link;
- Facebook without email fails;
- unverified Google email fails when required;
- duplicated local email fails;
- Client can be provisioned;
- Admin cannot be provisioned;
- local disabled user cannot login;
- JWT response has expected fields;
- unlinking last identity without password fails.

## Fake provider strategy

For tests, create a fake adapter that always returns an `ExternalIdentity`. This avoids real calls to Google, Facebook or Keycloak.
