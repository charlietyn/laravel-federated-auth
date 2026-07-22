<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Integrations\AppContext\AppContextDetector;
use Ronu\LaravelFederatedAuth\Services\TokenIssuers\JwtAuthTokenIssuer;
use Ronu\LaravelFederatedAuth\Tests\TestCase;

/**
 * ronu/laravel-app-context is a suggested, not required, dependency: this
 * package must boot and issue tokens without it. These tests run in a suite
 * where it is deliberately absent, which is what makes them meaningful.
 */
class AppContextIntegrationTest extends TestCase
{
    public function test_detector_reports_unavailable_when_app_context_is_not_installed(): void
    {
        $detector = new AppContextDetector;

        // Guards the test itself: if app-context ever becomes a real dependency
        // of this package the assertion below would silently stop testing
        // anything, so assert the precondition explicitly.
        $this->assertFalse(
            interface_exists($detector->tokenIssuerContract()),
            'app-context must stay out of this package\'s dependencies.'
        );

        $this->assertFalse($detector->available());
    }

    public function test_package_falls_back_to_the_stock_issuer_without_app_context(): void
    {
        $this->assertInstanceOf(
            JwtAuthTokenIssuer::class,
            $this->app->make(TokenIssuerInterface::class)
        );
    }

    public function test_detector_resolves_names_as_strings_so_it_never_autoloads_missing_classes(): void
    {
        $detector = new AppContextDetector;

        $this->assertSame(
            'Ronu\\AppContext\\Contracts\\ContextTokenIssuerInterface',
            $detector->tokenIssuerContract()
        );
        $this->assertSame('Ronu\\AppContext\\Context\\AppContext', $detector->contextClass());
    }
}
