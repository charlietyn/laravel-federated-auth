<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connectionName = config('federated-auth.identity_store.connection');
        $tableName = config('federated-auth.identity_store.table', 'federated_auth_identities');
        $connection = Schema::connection($connectionName);
        if ($connection->hasTable($tableName)) return;
        $connection->create($tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->nullable()->index();
            $table->string('user_id')->index();
            $table->string('provider', 80)->index();
            $table->string('provider_user_id', 255)->index();
            $table->string('provider_email', 255)->nullable()->index();
            $table->boolean('provider_email_verified')->default(false);
            $table->string('provider_name', 255)->nullable();
            $table->text('provider_avatar')->nullable();
            $table->json('claims')->nullable();
            $table->json('metadata')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'provider_user_id'], 'fed_auth_tenant_provider_uid_unique');
            $table->unique(['tenant_id', 'user_id', 'provider'], 'fed_auth_tenant_user_provider_unique');
        });
    }
    public function down(): void
    {
        Schema::connection(config('federated-auth.identity_store.connection'))->dropIfExists(config('federated-auth.identity_store.table', 'federated_auth_identities'));
    }
};
