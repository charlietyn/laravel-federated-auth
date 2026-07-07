<?php

namespace Ronu\LaravelFederatedAuth;

use Illuminate\Support\ServiceProvider;
use Ronu\LaravelFederatedAuth\Contracts\IdentityLinkRepositoryInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\Contracts\OAuthStateStoreInterface;
use Ronu\LaravelFederatedAuth\Contracts\RoleMapperInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserResolverInterface;
use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface;
use Ronu\LaravelFederatedAuth\Providers\AppleProviderAdapter;
use Ronu\LaravelFederatedAuth\Providers\FacebookProviderAdapter;
use Ronu\LaravelFederatedAuth\Providers\GenericOidcProviderAdapter;
use Ronu\LaravelFederatedAuth\Providers\GoogleProviderAdapter;
use Ronu\LaravelFederatedAuth\Providers\KeycloakProviderAdapter;
use Ronu\LaravelFederatedAuth\Repositories\DatabaseIdentityLinkRepository;
use Ronu\LaravelFederatedAuth\Services\CacheOAuthStateStore;
use Ronu\LaravelFederatedAuth\Services\ConfigurableUserResolver;
use Ronu\LaravelFederatedAuth\Services\DefaultUserStatusChecker;
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;
use Ronu\LaravelFederatedAuth\Services\IdentityProviderRegistry;
use Ronu\LaravelFederatedAuth\Services\NoopRoleMapper;
use Ronu\LaravelFederatedAuth\Services\NullUserProvisioner;
use Ronu\LaravelFederatedAuth\Services\TokenIssuers\JwtAuthTokenIssuer;

class FederatedAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/federated-auth.php', 'federated-auth');

        $this->app->singleton(IdentityProviderRegistryInterface::class, function ($app) {
            $registry = new IdentityProviderRegistry();
            $registry->register($app->make(GoogleProviderAdapter::class));
            $registry->register($app->make(FacebookProviderAdapter::class));
            $registry->register($app->make(AppleProviderAdapter::class));
            $registry->register($app->make(KeycloakProviderAdapter::class));
            $registry->register($app->make(GenericOidcProviderAdapter::class));

            return $registry;
        });

        foreach ($this->contractBindings() as $contract => $default) {
            $implementation = config("federated-auth.bindings.$contract", $default);
            $this->app->bind($contract, $implementation);
        }

        $this->app->singleton(FederatedAuthBroker::class);
        $this->app->alias(FederatedAuthBroker::class, 'federated-auth');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/federated-auth.php' => config_path('federated-auth.php'),
        ], 'federated-auth-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_federated_auth_identities_table.php' => database_path('migrations/2026_01_01_000000_create_federated_auth_identities_table.php'),
        ], 'federated-auth-migrations');

        $this->publishes([
            __DIR__.'/../docs' => base_path('docs/vendor/ronu/laravel-federated-auth'),
        ], 'federated-auth-docs');

        if (config('federated-auth.routes.enabled', true) && config('federated-auth.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/federated-auth.php');
        }
    }

    private function contractBindings(): array
    {
        return [
            IdentityLinkRepositoryInterface::class => DatabaseIdentityLinkRepository::class,
            OAuthStateStoreInterface::class => CacheOAuthStateStore::class,
            UserResolverInterface::class => ConfigurableUserResolver::class,
            UserProvisionerInterface::class => NullUserProvisioner::class,
            TokenIssuerInterface::class => JwtAuthTokenIssuer::class,
            UserStatusCheckerInterface::class => DefaultUserStatusChecker::class,
            RoleMapperInterface::class => NoopRoleMapper::class,
        ];
    }
}
