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
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

class OAuthStateContextRestorationTest extends TestCase
{
    public function test_callback_login_restores_tenant_user_type_channel_and_guard_from_consumed_state(): void
    {
        config()->set('federated-auth.security.oauth_state.enabled', true);
        config()->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'require_email' => false,
            'require_verified_email' => false,
            'auto_provision' => true,
            'allow_email_linking' => false,
            'allowed_user_types' => ['Client'],
        ]);

        $request = Request::create('/callback', 'GET', [
            'code' => 'auth-code',
            'state' => 'state-123',
        ]);

        $state = new OAuthAuthorizationState(
            state: 'state-123',
            provider: 'google',
            redirectUri: 'https://api.example.com/api/auth/federated/google/callback',
            tenantId: 'clinic-1',
            userType: 'Client',
            channel: 'web',
            guard: 'api',
            metadata: ['started_from' => 'tenant-login'],
        );

        $stateStore = new RecordingStateStore($state);
        $adapter = new RecordingCallbackAdapter;
        $links = new RecordingLinkRepository;

        $broker = new FederatedAuthBroker(
            providers: new SingleAdapterRegistry($adapter),
            links: $links,
            users: new NullUserResolver,
            provisioner: new FixedUserProvisioner(new ContextRestorationUser(99)),
            tokens: new ContextAwareTokenIssuer,
            statusChecker: new AllowAllStatusChecker,
            roleMapper: new NoopTestRoleMapper,
            states: $stateStore,
        );

        $result = $broker->loginFromCallback('google', AuthContext::fromRequest('google', $request));

        $this->assertSame('clinic-1', $adapter->context?->tenantId);
        $this->assertSame('Client', $adapter->context?->userType);
        $this->assertSame('web', $adapter->context?->channel);
        $this->assertSame('api', $adapter->context?->guard);
        $this->assertSame('https://api.example.com/api/auth/federated/google/callback', $adapter->context?->redirectUri);
        $this->assertSame($state, $adapter->context?->authorizationState);

        $this->assertSame('clinic-1', $links->createdContext?->tenantId);
        $this->assertSame('Client', $links->createdContext?->userType);
        $this->assertSame('api', $result->tokens['guard']);
        $this->assertSame(1, $stateStore->consumeCount);
    }
}

final class RecordingStateStore implements OAuthStateStoreInterface
{
    public int $consumeCount = 0;

    public function __construct(private readonly OAuthAuthorizationState $state) {}

    public function create(string $provider, AuthContext $context, array $attributes = []): OAuthAuthorizationState
    {
        return $this->state;
    }

    public function consume(string $provider, string $state, Request $request): OAuthAuthorizationState
    {
        $this->consumeCount++;

        return $this->state;
    }
}

final class SingleAdapterRegistry implements IdentityProviderRegistryInterface
{
    public function __construct(private readonly IdentityProviderAdapterInterface $adapter) {}

    public function register(IdentityProviderAdapterInterface $adapter): void {}

    public function adapterFor(string $provider): IdentityProviderAdapterInterface
    {
        return $this->adapter;
    }

    public function all(): array
    {
        return [$this->adapter];
    }
}

final class RecordingCallbackAdapter implements IdentityProviderAdapterInterface
{
    public ?AuthContext $context = null;

    public function name(): string
    {
        return 'google';
    }

    public function supports(string $provider): bool
    {
        return true;
    }

    public function redirectUrl(AuthContext $context): string
    {
        return 'https://provider.example.com/auth';
    }

    public function userFromCallback(AuthContext $context): ExternalIdentity
    {
        $this->context = $context;

        return new ExternalIdentity(
            provider: 'google',
            providerUserId: 'provider-user-1',
            email: 'client@example.com',
            emailVerified: true,
        );
    }

    public function userFromToken(string $token, AuthContext $context): ExternalIdentity
    {
        return new ExternalIdentity('google', 'provider-user-1');
    }
}

final class RecordingLinkRepository implements IdentityLinkRepositoryInterface
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

final class NullUserResolver implements UserResolverInterface
{
    public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }
}

final class FixedUserProvisioner implements UserProvisionerInterface
{
    public function __construct(private readonly Authenticatable $user) {}

    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable
    {
        return $this->user;
    }
}

final class ContextAwareTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        return new AuthResult($user, [
            'access_token' => 'local-token',
            'guard' => $context->guard,
            'tenant_id' => $context->tenantId,
        ]);
    }
}

final class AllowAllStatusChecker implements UserStatusCheckerInterface
{
    public function ensureCanLogin(Authenticatable $user, AuthContext $context): void {}
}

final class NoopTestRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void {}
}

final class ContextRestorationUser implements Authenticatable
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

    public function setRememberToken($value) {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
