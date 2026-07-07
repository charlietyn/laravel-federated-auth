# Documentation - ronu/laravel-federated-auth

This documentation explains how to install, configure, extend and safely use `ronu/laravel-federated-auth`.

The package is designed for senior Laravel systems where the users table, schema, authentication guard, token format and user provisioning rules vary from one project to another.

Recommended order:

0. `00-guia-para-juniors.md` - Spanish beginner-friendly guide with examples, flows and security explanations.
1. `01-installation.md`
2. `02-configuration-line-by-line.md`
3. `03-core-architecture.md`
4. `04-google-facebook.md`
5. `05-keycloak-oidc.md`
6. `06-kwikvet-integration-example.md`
7. `07-extending-contracts.md`
8. `08-security-and-edge-cases.md`
9. `09-testing.md`
10. `10-troubleshooting.md`
11. `11-line-by-line-request-flow.md`
12. `12-oauth-hardening.md`
13. `13-apple-provider.md`
14. `14-integracion-rest-generic-class.md` - Optional integration analysis with `ronu/rest-generic-class`.
15. `15-guia-junior-integracion-rgc.md` - Junior guide for enabling optional RGC response/permissions integration.

Core idea:

```text
Provider -> Adapter -> ExternalIdentity -> Resolver/Provisioner -> Identity Link -> TokenIssuer
```

Security hardening idea:

```text
Redirect request -> one-time state -> optional PKCE -> optional OIDC nonce -> callback validation -> local auth
```

Optional integration idea:

```text
Federated Auth core -> optional contracts/adapters -> Rest Generic Class permissions/response/admin CRUD
```
