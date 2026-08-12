<?php

namespace Ronu\LaravelFederatedAuth\Services\Errors;

use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;
use Ronu\LaravelFederatedAuth\Services\NullUserProvisioner;

/**
 * The default: capture nothing.
 *
 * Consistent with {@see NullUserProvisioner}
 * — upgrading the package must never start writing rows into a host's database
 * without the host asking for it. Error capture turns on by setting
 * `federated-auth.error_reporting.enabled` and naming at least one handler.
 */
final class NullErrorReporter implements ErrorReporterInterface
{
    public function report(FederatedAuthError $error): void
    {
        // Intentionally empty.
    }
}
