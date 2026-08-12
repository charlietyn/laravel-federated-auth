<?php

/*
|--------------------------------------------------------------------------
| Persisting federated auth failures to a host error table
|--------------------------------------------------------------------------
|
| Documentation sample — not autoloaded package code. Copy into your app.
|
| The package captures every broker failure (redirect, callback, token login,
| link, unlink) and hands it to whatever you configure. There are three levels
| of effort; pick the lowest one that fits.
|
|
| LEVEL 1 — config only, reuse an existing job
| ---------------------------------------------------------------------------
| If you already have a queued job that inserts an array into an error table
| (the common `App\Jobs\LogErrorToDatabase` shape), name it and you are done.
| The package constructs it with the payload row and dispatches it:
|
|   // config/federated-auth.php
|   'error_reporting' => [
|       'enabled' => true,
|       'handlers' => [
|           App\Jobs\LogErrorToDatabase::class,
|       ],
|       'queue' => ['queue' => 'logs', 'after_response' => true],
|       'payload' => [
|           // Trim the row to the columns your error_logs table actually has,
|           // otherwise a strict $fillable will reject the extra keys.
|           'only' => [
|               'description', 'ip', 'path', 'status_code', 'error',
|               'parameters', 'request', 'headers', 'user_id', 'username',
|               'created_at', 'updated_at',
|           ],
|       ],
|   ],
|
|
| LEVEL 2 — config only, reuse an existing service
| ---------------------------------------------------------------------------
| To write synchronously through a service you already have, use the
| 'Class@method' shape. The method is called with ($payload, $error); a method
| declaring only the first parameter is fine.
|
|   'handlers' => [
|       'App\Services\ErrorLogService@store',
|   ],
|
|
| LEVEL 3 — your own reporter class
| ---------------------------------------------------------------------------
| When you need to reshape the row, route by severity, or add fields the
| package cannot know about, implement ErrorReporterInterface. That is the
| class below.
|
*/

namespace App\Reporting;

use App\Jobs\LogErrorToDatabase;
use Illuminate\Support\Facades\Log;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Throwable;

/**
 * Maps a federated auth failure onto an existing `error_logs` table.
 *
 * Register it in config/federated-auth.php:
 *
 *   'bindings' => [
 *       ErrorReporterInterface::class => App\Reporting\ErrorLogReporter::class,
 *   ],
 *
 *   'error_reporting' => ['enabled' => true],
 *
 * Note that a bound reporter replaces the handler list entirely — the
 * `handlers` array is read by ConfigurableErrorReporter, which you have just
 * swapped out. `enabled` is still honoured, because the broker asks the
 * reporter and the reporter decides.
 */
class ErrorLogReporter implements ErrorReporterInterface
{
    public function report(FederatedAuthError $error): void
    {
        try {
            if (! $this->worthRecording($error)) {
                return;
            }

            // Already scrubbed: no authorization code, state, id_token,
            // access_token, client_secret, Authorization header or cookie
            // survives into this array.
            $row = $error->toArray();

            LogErrorToDatabase::dispatch([
                'description' => $row['description'],
                'ip' => $row['ip'],
                'path' => $row['path'],
                'status_code' => $row['status_code'],
                'error' => $row['error'],
                'parameters' => $row['parameters'],
                'request' => $row['request'],
                'headers' => $row['headers'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->onQueue('logs')->afterResponse();
        } catch (Throwable $exception) {
            // An error reporter that throws turns a handled login failure into
            // a 500. The broker guards against that too, but relying on the
            // guard would mean losing this failure silently.
            Log::critical('Failed to record a federated auth error', [
                'reporter_error' => $exception->getMessage(),
                'original_exception' => $error->exception::class,
                'operation' => $error->operation,
                'provider' => $error->context->provider,
            ]);
        }
    }

    /**
     * A user who leaves a login tab open overnight produces an expired state on
     * return. That is the protocol working, not an incident, and recording it
     * buries the failures that do need a human.
     */
    private function worthRecording(FederatedAuthError $error): bool
    {
        return ! $error->exception instanceof InvalidOAuthStateException;
    }
}
