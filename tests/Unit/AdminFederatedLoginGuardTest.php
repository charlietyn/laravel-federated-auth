<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Contracts\IdentityLinkRepositoryInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
use Ronu\LaravelFederatedAuth\DTO\LinkedIdentity;
use Ronu\LaravelFederatedAuth\DTO\OAuthAuthorizationState;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class AdminFederatedLoginGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('federated-auth.enabled', true);
        config()->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'require_email' => true,
            'require_verified_email' => true,
            'auto_provision' => true,
            'allow_email_linking' => true,
            'allowed_user_types' => ['Client', 'Admin'],
        ]);
        config()->set('federated-auth.security.prevent_admin_auto_provision', true);
        config()->set('federated-auth.security.admin_user_types', ['Admin']);
        config()->set('federated-auth.security.deny_unverified_email_linking', true);
    }

    public function test_existing_admin_can_login_by_verified_email_without_auto_provisioning(): void
    {
        $admin = new AdminGuardUser(42);
        $resolver = new AdminGuardResolver($admin);
        $provisioner = new RecordingAdminProvisioner($admin);
        $links = new AdminGuardLinkRepository;
        $broker = $this->broker($resolver, $provisioner, $links);

        $result = $broker->authenticateIdentity(
            $this->identity(),
            new AuthContext(provider: 'google', userType: 'Admin', channel: 'admin'),
        );

        $this->assertSame(42, $result->user->getAuthIdentifier());
        $this->assertFalse($result->wasProvisioned);
        $this->assertTrue($result->wasLinked);
        $this->assertSame(0, $provisioner->calls);
        $this->assertSame('Admin', $resolver->resolvedContext?->userType);
        $this->assertSame('admin', $links->createdContext?->channel);
    }

    public function test_missing_admin_is_rejected_before_provisioner_is_called(): void
    {
        $fallback = new AdminGuardUser(99);
        $resolver = new AdminGuardResolver(null);
        $provisioner = new RecordingAdminProvisioner($fallback);
        $broker = $this->broker($resolver, $provisioner, new AdminGuardLinkRepository);

        try {
            $broker->authenticateIdentity(
                $this->identity(),
                new AuthContext(provider: 'google', userType: 'Admin', channel: 'admin'),
            );
            $this->fail('Expected privileged auto-provisioning to be rejected.');
        } catch (ProviderDisabledException $exception) {
            $this->assertSame(
                'Admin users cannot be auto-provisioned through federated auth.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $provisioner->calls);
    }

    private function broker(
        UserResolverInterface $resolver,
        UserProvisionerInterface $provisioner,
        IdentityLinkRepositoryInterface $links,
    ): FederatedAuthBroker {
        return new FederatedAuthBroker(
            providers: new AdminGuardProviderRegistry,
            links: $links,
            users: $resolver,
            provisioner: $provisioner,
            tokens: new AdminGuardTokenIssuer,
            statusChecker: new AdminGuardStatusChecker,
            roleMapper: new AdminGuardRoleMapper,
            states: new AdminGuardStateStore,
        );
    }

    private function identity(): ExternalIdentity
    {
        return new ExternalIdentity(
            provider: 'google',
            providerUserId: 'google-admin-42',
            email: 'admin@example.com',
            emailVerified: true,
            name: 'Admin User',
        );
    }
}

final class AdminGuardResolver implements UserResolverInterface
{
    public ?AuthContext $resolvedContext = null;

    public function __construct(private readonly ?Authenticatable $user) {}

    public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable
    {
        $this->resolvedContext = $context;

        return $this->user;
    }

    public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        $this->resolvedContext = $context;

        return $this->user;
    }
}

final class RecordingAdminProvisioner implements UserProvisionerInterface
{
    public int $calls = 0;

    public function __construct(private readonly Authenticatable $user) {}

    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable
    {
        $this->calls++;

        return $this->user;
    }
}

final class AdminGuardLinkRepository implements IdentityLinkRepositoryInterface
{
    public ?AuthContext $createdContext = null;

    public function findByProviderIdentity(string $provider, string $providerUserId, AuthContext $context): ?LinkedIdentity
    {
        return null;
    }

    public function findByUserAndProvider(string|int $userId, string $provider, AuthContext $context): ?LinkedIdentity
    {
        return null;
    }

    public function create(string|int $userId, ExternalIdentity $identity, AuthContext $context): LinkedIdentity
    {
        $this->createdContext = $context;

        return new LinkedIdentity(1, $userId, $identity->provider, $identity->providerUserId, $context->tenantId);
    }

    public function touch(LinkedIdentity $linkedIdentity, ExternalIdentity $identity, AuthContext $context): void {}

    public function delete(LinkedIdentity $linkedIdentity, AuthContext $context): void {}

    public function countForUser(string|int $userId, AuthContext $context): int
    {
        return 1;
    }
}

final class AdminGuardProviderRegistry implements IdentityProviderRegistryInterface
{
    public function register(IdentityProviderAdapterInterface $adapter): void {}

    public function adapterFor(string $provider): IdentityProviderAdapterInterface
    {
        throw new \LogicException('Provider adapter is not used by authenticateIdentity().');
    }

    public function all(): array
    {
        return [];
    }
}

final class AdminGuardTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        return new AuthResult($user, [
            'access_token' => 'admin-token',
            'channel' => $context->channel,
        ]);
    }
}

final class AdminGuardStatusChecker implements UserStatusCheckerInterface
{
    public function ensureCanLogin(Authenticatable $user, AuthContext $context): void {}
}

final class AdminGuardRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void {}
}

final class AdminGuardStateStore implements OAuthStateStoreInterface
{
    public function create(string $provider, AuthContext $context, array $attributes = []): OAuthAuthorizationState
    {
        throw new \LogicException('State creation is not used by authenticateIdentity().');
    }

    public function consume(string $provider, string $state, Request $request): OAuthAuthorizationState
    {
        throw new \LogicException('State consumption is not used by authenticateIdentity().');
    }
}

final class AdminGuardUser implements Authenticatable
{
    public function __construct(private readonly int $id) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
