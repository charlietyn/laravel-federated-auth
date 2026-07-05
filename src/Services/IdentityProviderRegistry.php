<?php
namespace Ronu\LaravelFederatedAuth\Services;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface; use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface; use Ronu\LaravelFederatedAuth\Exceptions\ProviderNotSupportedException;
class IdentityProviderRegistry implements IdentityProviderRegistryInterface { private array $adapters=[]; public function register(IdentityProviderAdapterInterface $adapter): void { $this->adapters[]=$adapter; } public function adapterFor(string $provider): IdentityProviderAdapterInterface { foreach($this->adapters as $a){ if($a->supports($provider)) return $a; } throw new ProviderNotSupportedException("No identity provider adapter supports [$provider]."); } public function all(): array { return $this->adapters; } }
