<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;

interface AuthResponseFormatterInterface
{
    /**
     * Format the final API response for a successful federated authentication flow.
     */
    public function format(AuthResult $result, AuthContext $context): array;
}
