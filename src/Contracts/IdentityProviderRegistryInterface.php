<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

interface IdentityProviderRegistryInterface
{
    public function register(IdentityProviderAdapterInterface $adapter): void;

    public function adapterFor(string $provider): IdentityProviderAdapterInterface;

    public function all(): array;
}
