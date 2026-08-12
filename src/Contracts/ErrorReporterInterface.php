<?php

namespace Ronu\LaravelFederatedAuth\Contracts;

use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;

/**
 * Receives every error the broker captures, so a host application can persist
 * it wherever it already keeps its error log.
 *
 * The package deliberately owns no error table, no job and no severity policy —
 * exactly as it owns no user model. It normalizes the failure into a
 * {@see FederatedAuthError} and hands it over; where that lands is the host's
 * decision, expressed by binding this contract or by listing handlers in
 * `federated-auth.error_reporting.handlers`.
 *
 * Implementations MUST NOT throw. An error reporter that breaks authentication
 * is worse than no error reporter: the broker guards against it anyway, but an
 * implementation that relies on that guard is trading a logged failure for a
 * swallowed one.
 */
interface ErrorReporterInterface
{
    public function report(FederatedAuthError $error): void;
}
