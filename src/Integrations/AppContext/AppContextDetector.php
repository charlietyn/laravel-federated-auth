<?php

namespace Ronu\LaravelFederatedAuth\Integrations\AppContext;

/**
 * Detects whether ronu/laravel-app-context is installed alongside this package.
 *
 * The dependency is deliberately one-way and optional: federated auth works on
 * its own, and app-context works without federated auth. Names are resolved as
 * strings so this file never triggers an autoload of a class that may not exist.
 */
class AppContextDetector
{
    public function available(): bool
    {
        return interface_exists($this->tokenIssuerContract())
            && class_exists($this->contextClass());
    }

    public function tokenIssuerContract(): string
    {
        return 'Ronu\\AppContext\\Contracts\\ContextTokenIssuerInterface';
    }

    public function contextClass(): string
    {
        return 'Ronu\\AppContext\\Context\\AppContext';
    }
}
