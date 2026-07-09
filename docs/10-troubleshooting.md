# 10 - Troubleshooting

## Provider disabled

Set:

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
```

## Missing provisioner

Error:

```text
Auto provisioning is enabled but no UserProvisionerInterface implementation was configured.
```

Fix: bind a custom provisioner.

## Guard does not support login

Fix: bind a custom `TokenIssuerInterface`.

## Identity table missing

Run the isolated package migration:

```bash
php artisan federated-auth:migrate
```

Or publish it and migrate with the rest of your app:

```bash
php artisan vendor:publish --tag=federated-auth-migrations
php artisan migrate
```

Or configure a custom table and create your own migration.

## OIDC issuer failed

Check the exact issuer URL. For Keycloak it usually ends with:

```text
/realms/{realm}
```

## OIDC audience failed

Check that `client_id` matches the ID token audience.
