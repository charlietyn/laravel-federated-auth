<?php

namespace Ronu\LaravelFederatedAuth\Services;

use Illuminate\Support\Facades\DB;
use Ronu\LaravelFederatedAuth\Contracts\AuthenticationTransactionInterface;

class DatabaseAuthenticationTransaction implements AuthenticationTransactionInterface
{
    public function run(callable $callback): mixed
    {
        if (! (bool) config('federated-auth.transactions.enabled', true)) {
            return $callback();
        }

        $connection = config(
            'federated-auth.transactions.connection',
            config('federated-auth.identity_store.connection')
        );
        $attempts = max(1, (int) config('federated-auth.transactions.attempts', 3));

        return DB::connection($connection)->transaction($callback, $attempts);
    }
}
