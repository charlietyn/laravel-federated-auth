# 18 — Error reporting (persisting failures)

The log subscriber (`docs/12`, README → Observability) writes a structured line
to your log channel. This document covers the other half: **durable capture**,
where every failure the broker raises is handed to code you control so it can
land in a database table, an incident tracker, or an APM.

The two are independent. Run either, both, or neither.

---

## 1. What gets captured

Every public broker operation, not just login:

| Operation constant | Raised by |
|---|---|
| `FederatedAuthError::OPERATION_REDIRECT` | `redirectUrl()` |
| `FederatedAuthError::OPERATION_LOGIN_CALLBACK` | `loginFromCallback()` |
| `FederatedAuthError::OPERATION_LOGIN_TOKEN` | `loginFromToken()` |
| `FederatedAuthError::OPERATION_LINK` | `linkIdentity()` |
| `FederatedAuthError::OPERATION_UNLINK` | `unlink()` |

The redirect leg matters more than it looks. If building the authorization URL
fails, no callback ever arrives, so no login failure is ever recorded — the user
just sees a button that does nothing. Without capture here that class of outage
is invisible.

**Capture never changes control flow.** The original exception is always
rethrown to the caller, and a reporter that throws is swallowed. Losing an
authentication error because the error logger failed would be the worst possible
outcome, so the broker guards the reporter and the shipped reporter guards each
handler.

---

## 2. Turning it on

Off by default — upgrading the package must never start writing rows into your
database unasked.

```php
// config/federated-auth.php
'error_reporting' => [
    'enabled' => true,
    'handlers' => [
        App\Jobs\LogErrorToDatabase::class,
    ],
],
```

```env
FEDERATED_AUTH_ERROR_REPORTING_ENABLED=true
```

Two things must both be true: `enabled`, and at least one handler.

---

## 3. The four handler shapes

`ConfigurableErrorReporter` is the default binding and inspects each entry:

### 3.1 A class implementing `ErrorReporterInterface`

Resolved from the container, called with the DTO. Use this when you want the
live exception and context objects.

```php
'handlers' => [App\Reporting\ErrorLogReporter::class],
```

### 3.2 A queued job

Anything implementing `ShouldQueue` is constructed with the **payload array**
and dispatched. This is the shape of a typical `LogErrorToDatabase` job, so an
app that already has one needs no new code at all:

```php
'handlers' => [App\Jobs\LogErrorToDatabase::class],

'queue' => [
    'connection' => null,
    'queue' => 'logs',
    'after_response' => true,
],
```

`after_response` defers the insert until the response is sent. It relies on the
terminable middleware stack, which does not run under `queue:work` or in tests —
hence the `false` default.

A `ShouldQueue` job that does not use the `Queueable` trait is dispatched
without routing rather than fataling on a missing `onQueue()`.

### 3.3 `'Service@method'`

Resolved from the container and called as `$service->method($payload, $error)`.
This reuses an existing service class verbatim:

```php
'handlers' => ['App\Services\ErrorLogService@store'],
```

### 3.4 Any invokable class or closure

Called via `__invoke()`, `handle()` or `report()` with `($payload, $error)`.

Handlers always receive two arguments, but a method declaring only the first
still works — PHP allows extra arguments to userland functions. An existing
`handle(array $data)` needs no change.

Every configured handler runs; one that throws is logged to `Log::critical` and
does not stop the others.

---

## 4. The payload

`$error->toArray()` returns:

| Key | Notes |
|---|---|
| `description` | Multi-line human summary: type, status, message, location, trace. |
| `error` | The exception message, scrubbed. |
| `exception`, `code`, `file`, `line` | Exception identity. |
| `status_code` | The package's opinion of the HTTP meaning (see below). |
| `operation` | One of the five constants above. |
| `provider`, `channel`, `user_type`, `tenant_id`, `guard` | From `AuthContext`. |
| `state_digest` | Truncated SHA-256 of the one-time state. |
| `provider_user_id` | When an identity was resolved before the failure. |
| `email` | `null` unless `payload.include_email` is true. |
| `ip`, `path` | From the request. |
| `parameters`, `request`, `headers` | JSON strings, scrubbed. Never null — `{}` when nothing was captured, since these columns are commonly NOT NULL. |
| `user_id`, `username` | The acting user, when there is one. |
| `created_at`, `updated_at` | `now()`. |

Key names deliberately match the columns an application error table usually
already has, so a host job can insert the array as-is.

If your table is narrower, trim rather than remap — a model with a strict
`$fillable` throws on an unknown key:

```php
'payload' => [
    'only' => [
        'description', 'ip', 'path', 'status_code', 'error',
        'parameters', 'request', 'headers', 'user_id', 'username',
        'created_at', 'updated_at',
    ],
],
```

### Status codes

Package exceptions carry no HTTP status of their own — your exception handler
decides that. `status_code` is the package's opinion, so a row is triageable
without re-reading the class: `401` for invalid state/token/unverified email,
`403` for disabled package/provider/user and provisioning refusals, `404` for an
unsupported provider, `409` for link conflicts, `422` for missing or ambiguous
email, `500` otherwise. A Symfony `HttpExceptionInterface` keeps its own status.

---

## 5. Security: an error table is not a credential store

This is the one feature in the package most likely to leak an OAuth secret by
accident. Provider SDKs put the authorization code in the exception message,
Guzzle puts the full request URL (query string included) in it, and a dump of
`$request->all()` on the callback leg contains `code` and `state` verbatim.

A row in an error table outlives the seconds in which those values are useful to
an attacker only if nobody ever reads the table.

So everything leaving `toArray()` passes through `SensitiveDataScrubber` first:

- Values under `code`, `state`, `code_verifier`, `nonce`, `id_token`,
  `access_token`, `refresh_token`, `client_secret`, `authorization`, `cookie`,
  `x-api-key`, `password` and friends — recursively, in arrays.
- `key=value` pairs inside free text and URLs (`?code=…`, `&state=…`).
- `"key":"value"` pairs inside JSON fragments.
- Bare JWTs anywhere in a string (`eyJ…`.`…`.`…`).
- `Bearer` / `Basic` credentials.

**This is not configurable off.** `payload.sensitive_keys` adds keys; it cannot
remove the defaults. Stack frame *arguments* are never included either — the
provider token is an argument to half the frames in this package.

What survives is `state_digest`, which joins the row to the redirect leg in your
log without being replayable.

Email is withheld unless `payload.include_email` is true: it is personal data,
and on Apple it is a private-relay address that identifies the user to the
provider.

---

## 6. Filtering the noise

```php
'ignore_exceptions' => [
    Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException::class,
],
'only_exceptions' => [],
```

`ignore_exceptions` is evaluated first. A user who leaves a login tab open
overnight produces an expired state on return — that is the protocol working,
not an incident, and recording it buries the failures that need a human.

`only_exceptions`, when non-empty, narrows capture to just those classes.

---

## 7. Taking full control

Bind your own implementation and the handler list stops being consulted — you
have replaced the class that reads it:

```php
'bindings' => [
    ErrorReporterInterface::class => App\Reporting\ErrorLogReporter::class,
],
```

`enabled` is still honoured by whatever you write, because the broker asks the
reporter and the reporter decides.

To disable capture outright regardless of config, bind the null object:

```php
ErrorReporterInterface::class => Ronu\LaravelFederatedAuth\Services\Errors\NullErrorReporter::class,
```

See `examples/ronu/ErrorLogReporter.php` for a worked host implementation.

---

## 8. Testing it

```php
config()->set('federated-auth.error_reporting.enabled', true);
config()->set('federated-auth.error_reporting.handlers', [
    function (array $payload, FederatedAuthError $error) use (&$rows) {
        $rows[] = $payload;
    },
]);
```

For the job shape, `Queue::fake()` and `Queue::assertPushedOn('logs', …)`.

`tests/Unit/ErrorReportingTest.php` covers all four handler shapes, the
filtering rules, the scrubbing guarantees, and the rule that a broken handler
never replaces the authentication error.
