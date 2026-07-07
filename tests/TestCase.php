<?php

namespace Ronu\LaravelFederatedAuth\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ronu\LaravelFederatedAuth\FederatedAuthServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FederatedAuthServiceProvider::class];
    }
}
