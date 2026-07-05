# 11 - Line by line request flow

Request:

```http
POST /api/auth/federated/google/token
Content-Type: application/json

{
  "access_token": "provider-token",
  "user_type": "Client"
}
```

## Controller

```php
$validated = $request->validate([...]);
```

Validates required input.

```php
$context = AuthContext::fromRequest($provider, $request);
```

Builds a context object with provider, tenant, user type, guard, channel, IP and user agent.

```php
$result = $this->broker->loginFromToken(...);
```

Delegates authentication to the broker.

## Broker

```php
$this->ensurePackageEnabled();
```

Stops the flow if the package is disabled.

```php
$identity = $adapter->userFromToken($token, $context);
```

Converts provider token into `ExternalIdentity`.

```php
$this->validateIdentity($identity, $providerConfig);
```

Checks email, verified email, allowed user type and admin auto-provision rules.

```php
$linked = $this->links->findByProviderIdentity(...);
```

Finds existing local link.

If not found:

```php
$user = $this->provisioner->provision($identity, $context);
```

The application creates the user according to its own rules.

```php
$this->links->create(...);
```

Stores the external identity link.

```php
$result = $this->tokens->issue($user, $context);
```

Emits JWT, Sanctum token, session or custom token.
