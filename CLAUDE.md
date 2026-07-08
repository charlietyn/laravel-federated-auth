# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ronu/laravel-federated-auth` is a **standalone Composer package** (not an app) — a contract-first Laravel 11/12 bridge for external identity providers (Google, Facebook, Apple, Keycloak, generic OIDC). It deliberately owns no user model, schema, guard, token system, or tenancy strategy; the host application supplies those via interface bindings. The guiding rule: **external providers authenticate identity; the host app owns authorization.**

Namespace: `Ronu\LaravelFederatedAuth\` → `src/`. Tests: `Ronu\LaravelFederatedAuth\Tests\` → `tests/`.

## Commands

```bash
composer install

composer test              # phpunit (all)
composer test:unit         # phpunit --testsuite Unit
vendor/bin/phpunit --filter OAuthSecurityTest        # single test class
vendor/bin/phpunit --filter test_method_name         # single test method

composer pint              # laravel/pint auto-format
composer pint:test         # check formatting without writing
composer qa                # pint:test + test (run before committing)
```

Only one PHPUnit test suite exists (`Unit`, from `tests/Unit`). Tests extend `Tests\TestCase`, which boots the package via Orchestra Testbench — there is no host Laravel app in this repo.

## Architecture

The flow is a fixed pipeline (see README diagram). `FederatedAuthBroker` (`src/Services/FederatedAuthBroker.php`) is the orchestrator and the single entry point for every auth operation (`redirectUrl`, `loginFromCallback`, `loginFromToken`, `linkIdentity`, `unlink`). It is bound as a singleton and aliased `federated-auth`; the `FederatedAuth` facade and `FederatedAuthController` both go through it.

Pipeline: **Provider adapter** normalizes the provider response into an `ExternalIdentity` DTO → broker validates it (`validateIdentity`) → `UserResolverInterface` finds or `UserProvisionerInterface` provisions the local user → `IdentityLinkRepositoryInterface` records the link → `RoleMapperInterface` syncs roles → `TokenIssuerInterface` issues a local token → `AuthResponseFormatterInterface` shapes the JSON. Result is an `AuthResult` DTO.

### Contract-first design (the core idea)

`FederatedAuthServiceProvider::contractBindings()` maps every interface in `src/Contracts/` to a default implementation, but each binding is overridable via `config('federated-auth.bindings.<Interface>')`. **The host app customizes behavior by binding its own implementations — not by editing package code.** The `examples/` directory shows real host implementations (`RonuUserProvisioner`, `RonuJwtTokenIssuer`, `StandardUserProvisioner`) — these are documentation samples, not autoloaded package code.

Defaults worth knowing: `UserProvisionerInterface` → `NullUserProvisioner` (provisioning is off until the host binds one), `TokenIssuerInterface` → `JwtAuthTokenIssuer`, `RoleMapperInterface` → `NoopRoleMapper`, `OAuthStateStoreInterface` → `CacheOAuthStateStore`. Alternative token issuers ship in `src/Services/TokenIssuers/` (Sanctum, Session).

### Provider adapters

Adapters implement `IdentityProviderAdapterInterface` and are registered into the singleton `IdentityProviderRegistry` in the service provider. `supports()` matches a provider config's `driver` key. Two families:

- **Socialite-backed** (`SocialiteProviderAdapter` → Google, Facebook): `driver: socialite`. Get package-managed one-time OAuth `state` but **no package-level nonce/PKCE**. `driver()` injects credentials into `services.*` config at runtime and forces `stateless()` because the package validates state itself.
- **OIDC-style** (`GenericOidcProviderAdapter`, `KeycloakProviderAdapter`, `AppleProviderAdapter`): `driver: oidc` (or dedicated). These control the code flow directly and apply nonce + PKCE. Token decoding/validation uses `firebase/php-jwt` and helpers in `src/Support/` (`OAuthSecurity`, `ClaimReader`).

When adding a provider: create the adapter, register it in `FederatedAuthServiceProvider::register()`, and add a `providers.<name>` block to `config/federated-auth.php`.

### Security model (do not weaken)

- **Identity key is `tenant_id + provider + provider_user_id`, never email.** Apple returns private-relay emails; some providers omit or don't verify email. Email-based linking is opt-in per provider (`allow_email_linking`) and gated by `deny_unverified_email_linking`.
- OAuth `state` is one-time and consumed on callback (`contextForCallback` in the broker). Provider callbacks carry only `code`+`state`; tenant/user_type/channel/guard/redirect_uri are **restored from the stored state** via `AuthContext::withAuthorizationState()`, not from the callback request.
- Redirect URIs are validated by `OAuthSecurity::validateRedirectUri()` (HTTPS-only unless localhost-http is explicitly allowed; optional host allow-list).
- `validateIdentity()` blocks auto-provisioning of admin user types (`prevent_admin_auto_provision`) and enforces `allowed_user_types`. Do not auto-provision privileged users from public social providers.
- Provider tokens are not persisted by default.

### DTOs & context

`AuthContext` (immutable, `with*()` returns copies) carries request-scoped data through the whole pipeline — provider, tenant, user_type, channel, guard, redirect_uri, and the resolved `OAuthAuthorizationState`. `AuthContext::fromRequest()` also derives `providerTokenType` (`id_token` vs `access_token`) so the token flow stays explicit. `ExternalIdentity` is the normalized provider result; `AuthResult` bundles the authenticated user, tokens, and `was_provisioned`/`was_linked` flags.

### Optional integrations

`src/Integrations/RestGenericClass/` bridges the optional `ronu/rest-generic-class` package **without a hard dependency** — `RestGenericClassDetector` checks for its presence at runtime, and the formatter/permission-resolver are only used when enabled via config. Follow this soft-dependency pattern for any future optional integration.

## Conventions

- Config keys live in `config/federated-auth.php`; every runtime toggle is read via `config('federated-auth.*')`. Read config through `Support\ProviderConfig` for provider blocks.
- Routes (`routes/federated-auth.php`) only load when both `federated-auth.enabled` and `federated-auth.routes.enabled` are true; prefix/middleware/name are all config-driven.
- Domain errors are typed exceptions in `src/Exceptions/` (all extend `FederatedAuthException`) — throw these rather than generic exceptions so hosts can catch by type.
- Docs in `docs/` are the canonical deep reference (architecture, security, per-provider, request flow). Files 00 and 15 target juniors; 12 covers OAuth hardening; 13 covers Apple.
- This is a library published to Packagist — keep the public API (contracts, DTOs, config keys) stable and backward-compatible.
