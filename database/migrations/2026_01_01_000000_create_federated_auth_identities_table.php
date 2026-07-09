<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connectionName = config('federated-auth.identity_store.connection');
        $tableName = config('federated-auth.identity_store.table', 'federated_auth_identities');
        $connection = Schema::connection($connectionName);
        if ($connection->hasTable($tableName)) {
            return;
        }
        $connection->create($tableName, function (Blueprint $table) {
            $table->comment('Links external identity-provider accounts (Google, Facebook, Apple, Keycloak, OIDC) to local application users, scoped by tenant. One row represents a single provider identity owned by one local user within one tenant.');

            $table->bigIncrements('id')
                ->comment('Internal auto-incrementing surrogate primary key. Used only for storage/ordering; never exposed to clients.');
            $table->uuid('uuid')
                ->comment('Stable, publicly shareable identifier for this identity link. Safe to expose in APIs and logs without leaking the sequential primary key.');
            $table->string('tenant_id')->nullable()->index()
                ->comment('Tenant/organization scope that owns this identity link. Nullable for single-tenant deployments; part of the composite uniqueness keys so the same provider account can exist independently per tenant.');
            $table->string('user_id')->index()
                ->comment('Identifier of the local application user this external identity is linked to. Kept as a string to support both integer and UUID primary keys on the host user table; not a hard foreign key because the user table is owned by the host application.');
            $table->string('provider', 80)->index()
                ->comment('Federated provider key as configured under federated-auth.providers (e.g. google, facebook, apple, keycloak, oidc).');
            $table->string('provider_user_id', 255)->index()
                ->comment('Stable, provider-issued unique subject identifier (OIDC "sub"). This is the authoritative federated identity key — email is intentionally never used, as some providers return private-relay or unverified addresses.');
            $table->string('provider_email', 255)->nullable()->index()
                ->comment('Email address reported by the provider at link time, stored for display and optional email-based account matching. Informational only and must not be treated as a unique or trusted identifier.');
            $table->boolean('provider_email_verified')->default(false)
                ->comment('Whether the provider asserted the email as verified. Gates optional email-based linking; defaults to false so unverified emails are never implicitly trusted.');
            $table->string('provider_name', 255)->nullable()
                ->comment('Display name supplied by the provider profile, cached for convenience. Not authoritative and may be stale relative to the provider.');
            $table->text('provider_avatar')->nullable()
                ->comment('URL of the profile picture/avatar returned by the provider. Stored as text because avatar URLs can exceed typical VARCHAR limits.');
            $table->json('claims')->nullable()
                ->comment('Raw normalized claim set (OIDC claims, roles, groups) returned by the provider on the last successful authentication. Retained for role/permission mapping and auditing.');
            $table->json('metadata')->nullable()
                ->comment('Application-defined extension bag for additional context (e.g. channel, device, custom flags) that does not warrant a dedicated column.');
            $table->text('access_token')->nullable()
                ->comment('Provider access token from the last authentication. Nullable and populated only when the host application explicitly opts in to token storage; the package does not persist provider tokens by default.');
            $table->text('refresh_token')->nullable()
                ->comment('Provider refresh token used to obtain new access tokens without re-authentication. Sensitive; stored only when token persistence is explicitly enabled and should be encrypted at the application layer.');
            $table->timestamp('token_expires_at')->nullable()
                ->comment('Absolute expiry time of the stored provider access token, used to decide when a refresh is required. Null when no token is stored.');
            $table->timestamp('last_login_at')->nullable()
                ->comment('Timestamp of the most recent successful authentication through this identity link. Updated on every login/touch for auditing and inactivity analysis.');
            $table->timestamps();
            // Explicitly named unique so up()/down() and host-side drops behave identically across MySQL and PostgreSQL.
            // On PostgreSQL a UNIQUE is a constraint backed by an index; drop it with dropUnique('fed_auth_uuid_unique'),
            // never dropIndex(), or Postgres raises "cannot drop index ... because constraint ... requires it" (SQLSTATE 2BP01).
            $table->unique(['uuid'], 'fed_auth_uuid_unique');
            $table->unique(['tenant_id', 'provider', 'provider_user_id'], 'fed_auth_tenant_provider_uid_unique');
            $table->unique(['tenant_id', 'user_id', 'provider'], 'fed_auth_tenant_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::connection(config('federated-auth.identity_store.connection'))->dropIfExists(config('federated-auth.identity_store.table', 'federated_auth_identities'));
    }
};
