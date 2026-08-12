# Documentation - ronu/laravel-federated-auth

This documentation explains how to install, configure, extend and safely use `ronu/laravel-federated-auth`.

The package is designed for senior Laravel systems where the users table, schema, authentication guard, token format and user provisioning rules vary from one project to another.

## Naming in these docs

Two names recur throughout and mean different things:

| Name | What it is |
|---|---|
| `ronu/laravel-federated-auth`, `Ronu\LaravelFederatedAuth\` | **This package.** The Composer vendor and PHP namespace. Real, and not something you change. |
| **`AppProject`** (`app-project`) | **A fictional host application.** The placeholder for *your* Laravel app in every example — bindings, provisioners, token issuers, Keycloak realms, role prefixes. |

`AppProject` deliberately names nothing real. Wherever you see it — the
`AppProjectUserProvisioner` class, `KEYCLOAK_REALM=app-project`, an
`app-project-admin` role — substitute your own application's naming. It is a
worked example, never a requirement, and the package never reads it.

`StandardUserProvisioner` in `examples/standard/` is the same idea for a plain
Laravel app with a stock `users` table.

Recommended order:

0. `00-simple-guide.md` - Beginner-friendly guide with examples, flows and security explanations.
1. `01-installation.md`
2. `02-configuration-line-by-line.md`
3. `03-core-architecture.md`
4. `04-google-facebook.md`
5. `05-keycloak-oidc.md`
6. `06-app-project-integration-example.md`
7. `07-extending-contracts.md`
8. `08-security-and-edge-cases.md`
9. `09-testing.md`
10. `10-troubleshooting.md`
11. `11-line-by-line-request-flow.md`
12. `12-oauth-hardening.md`
13. `13-apple-provider.md`
14. `14-rest-generic-class-integration.md` - Optional integration analysis with `ronu/rest-generic-class`.
15. `15-guide-integration.md` - Junior guide for enabling optional RGC response/permissions integration.
16. `16-email-linking-by-user-type.md`
17. `17-channel-clients-and-atomic-registration.md`
18. `18-error-reporting.md` - Persisting broker failures to your own error table, and what is scrubbed before they get there.

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
