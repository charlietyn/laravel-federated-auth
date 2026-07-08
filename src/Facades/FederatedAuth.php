<?php

namespace Ronu\LaravelFederatedAuth\Facades;

use Illuminate\Support\Facades\Facade;

class FederatedAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'federated-auth';
    }
}
