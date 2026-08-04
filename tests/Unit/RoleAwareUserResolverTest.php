<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\Services\ConfigurableUserResolver;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class RoleAwareUserResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('resolver_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('user_type');
            $table->string('password')->nullable();
        });
        Schema::create('resolver_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('resolver_role_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('enabled')->default(true);
        });

        config()->set('federated-auth.user.model', ResolverUser::class);
        config()->set('federated-auth.user.primary_key', 'id');
        config()->set('federated-auth.user.columns.email', 'email');
        config()->set('federated-auth.user.columns.type', 'user_type');
        config()->set('federated-auth.security.allow_trusted_cross_user_type_resolution', true);
        config()->set('federated-auth.security.role_aware_user_resolution', [
            'enabled' => true,
            'relation' => 'roles',
            'column' => 'name',
            'map' => [
                'Client' => 'Client',
                'Technician' => 'Technician',
                'Veterinarian' => 'Veterinarian',
                'Admin' => 'Admin',
            ],
        ]);
    }

    public function test_historical_client_with_active_admin_role_resolves_as_admin(): void
    {
        $user = ResolverUser::query()->create([
            'email' => 'multi@example.test',
            'user_type' => 'Client',
        ]);
        $admin = ResolverRole::query()->create(['name' => 'Admin']);
        $user->allRoles()->attach($admin->getKey(), ['enabled' => true]);

        $resolved = (new ConfigurableUserResolver)->resolveById(
            $user->getKey(),
            new AuthContext(provider: 'google', userType: 'Admin', channel: 'admin'),
        );

        $this->assertSame($user->getKey(), $resolved?->getAuthIdentifier());
    }

    public function test_pending_disabled_admin_role_cannot_authenticate_as_admin(): void
    {
        $user = ResolverUser::query()->create([
            'email' => 'pending@example.test',
            'user_type' => 'Client',
        ]);
        $admin = ResolverRole::query()->create(['name' => 'Admin']);
        $user->allRoles()->attach($admin->getKey(), ['enabled' => false]);

        $resolved = (new ConfigurableUserResolver)->resolveById(
            $user->getKey(),
            new AuthContext(provider: 'google', userType: 'Admin', channel: 'admin'),
        );

        $this->assertNull($resolved);
    }

    public function test_trusted_registration_can_find_account_before_target_role_exists(): void
    {
        $user = ResolverUser::query()->create([
            'email' => 'client@example.test',
            'user_type' => 'Client',
        ]);
        $identity = new ExternalIdentity(
            provider: 'google',
            providerUserId: 'google-subject',
            email: 'client@example.test',
            emailVerified: true,
        );
        $context = new AuthContext(
            provider: 'google',
            userType: 'Technician',
            channel: 'site',
            metadata: ['trusted_multi_role_registration' => true],
        );

        $resolved = (new ConfigurableUserResolver)->resolveByEmail($identity, $context);

        $this->assertSame($user->getKey(), $resolved?->getAuthIdentifier());
    }
}

final class ResolverUser extends Authenticatable
{
    protected $table = 'resolver_users';

    public $timestamps = false;

    protected $guarded = [];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            ResolverRole::class,
            'resolver_role_user',
            'user_id',
            'role_id',
        )->wherePivot('enabled', true);
    }

    public function allRoles(): BelongsToMany
    {
        return $this->belongsToMany(
            ResolverRole::class,
            'resolver_role_user',
            'user_id',
            'role_id',
        )->withPivot('enabled');
    }
}

final class ResolverRole extends Model
{
    protected $table = 'resolver_roles';

    public $timestamps = false;

    protected $guarded = [];
}
