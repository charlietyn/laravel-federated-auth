<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

interface AuthenticationTransactionInterface
{
    /**
     * Execute identity linking, user provisioning and role/profile mapping as
     * one atomic operation.
     */
    public function run(callable $callback): mixed;
}
