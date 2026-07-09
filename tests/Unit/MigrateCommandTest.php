<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class MigrateCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('federated-auth:migrate', $this->app[Kernel::class]->all());
    }

    public function test_it_migrates_only_the_package_identity_store_table(): void
    {
        $this->assertFalse(Schema::hasTable('federated_auth_identities'));

        $this->artisan('federated-auth:migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('federated_auth_identities'));
    }

    public function test_it_can_roll_back_the_package_migration(): void
    {
        $this->artisan('federated-auth:migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('federated_auth_identities'));

        $this->artisan('federated-auth:migrate', ['--rollback' => true, '--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('federated_auth_identities'));
    }
}
