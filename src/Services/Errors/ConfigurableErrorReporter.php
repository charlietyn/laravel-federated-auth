<?php

namespace Ronu\LaravelFederatedAuth\Services\Errors;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;
use Throwable;

/**
 * Fans a captured error out to the handlers named in
 * `federated-auth.error_reporting.handlers`.
 *
 * This is the configuration-only path: a host that already has an error table,
 * a logging job or an error service points this at it and writes no glue code.
 * A host that wants full control binds {@see ErrorReporterInterface} to its own
 * class instead and never touches this one.
 *
 * Four handler shapes are accepted, resolved in this order:
 *
 *  1. A class implementing {@see ErrorReporterInterface} — resolved from the
 *     container, called with the DTO: `report($error)`.
 *  2. A queued job (implements `ShouldQueue`) — constructed with the payload
 *     array and dispatched: `new Job($payload)`. This is the shape of a typical
 *     `LogErrorToDatabase` job.
 *  3. `'Some\Service@method'` — resolved from the container, called as
 *     `$service->method($payload, $error)`. This is how an existing service
 *     class (a repository, a `BaseService` subclass) is reused as-is.
 *  4. Any other class or closure — resolved from the container and called via
 *     `__invoke()` or `handle()` with `($payload, $error)`.
 *
 * Handlers receive extra arguments they may ignore: PHP allows a userland
 * `handle(array $data)` to be called with two arguments, so a host method that
 * only wants the row does not have to declare a parameter for the DTO.
 */
class ConfigurableErrorReporter implements ErrorReporterInterface
{
    /**
     * Guards against an error loop: a handler that itself fails would otherwise
     * be reported through the same path that just failed.
     */
    private bool $reporting = false;

    public function __construct(private readonly Container $container) {}

    public function report(FederatedAuthError $error): void
    {
        if ($this->reporting || ! $this->shouldReport($error)) {
            return;
        }

        $this->reporting = true;

        try {
            $handlers = $this->handlers();

            if ($handlers === []) {
                return;
            }

            // Built once and shared: serializing the request and the stack trace
            // is the expensive part, and every handler wants the same row.
            $payload = $error->toArray();

            foreach ($handlers as $handler) {
                $this->dispatchTo($handler, $payload, $error);
            }
        } catch (Throwable $exception) {
            $this->fallback('Federated auth error reporting failed', $exception, $error);
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchTo(mixed $handler, array $payload, FederatedAuthError $error): void
    {
        try {
            if ($handler instanceof Closure) {
                $handler($payload, $error);

                return;
            }

            if ($handler instanceof ErrorReporterInterface) {
                $handler->report($error);

                return;
            }

            if (is_string($handler)) {
                $this->dispatchToClass($handler, $payload, $error);

                return;
            }

            if (is_object($handler)) {
                $this->callObject($handler, $payload, $error);

                return;
            }

            if (is_callable($handler)) {
                $handler($payload, $error);

                return;
            }

            $this->fallback(
                'Federated auth error handler is not callable',
                new \InvalidArgumentException('Unsupported handler of type '.get_debug_type($handler)),
                $error
            );
        } catch (Throwable $exception) {
            // One broken handler must not stop the others, and must never
            // surface to the user in place of the authentication error.
            $this->fallback('Federated auth error handler threw', $exception, $error);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchToClass(string $handler, array $payload, FederatedAuthError $error): void
    {
        // Shape 3: 'Service@method' — an existing service reused verbatim.
        if (str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $this->container->make($class)->{$method}($payload, $error);

            return;
        }

        if (! class_exists($handler)) {
            throw new \InvalidArgumentException("Federated auth error handler [$handler] does not exist.");
        }

        // Shape 1: a first-class reporter gets the DTO, not the row.
        if (is_subclass_of($handler, ErrorReporterInterface::class)) {
            $this->container->make($handler)->report($error);

            return;
        }

        // Shape 2: a queued job is constructed with the row, never resolved from
        // the container — its constructor takes the payload, which the container
        // cannot supply.
        if (is_subclass_of($handler, ShouldQueue::class)) {
            $this->dispatchJob(new $handler($payload));

            return;
        }

        $this->callObject($this->container->make($handler), $payload, $error);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callObject(object $handler, array $payload, FederatedAuthError $error): void
    {
        if ($handler instanceof ErrorReporterInterface) {
            $handler->report($error);

            return;
        }

        if ($handler instanceof ShouldQueue) {
            $this->dispatchJob($handler);

            return;
        }

        $method = match (true) {
            method_exists($handler, '__invoke') => '__invoke',
            method_exists($handler, 'handle') => 'handle',
            method_exists($handler, 'report') => 'report',
            default => throw new \InvalidArgumentException(
                'Federated auth error handler ['.$handler::class.'] has no __invoke(), handle() or report() method.'
            ),
        };

        $handler->{$method}($payload, $error);
    }

    private function dispatchJob(object $job): void
    {
        $queue = config('federated-auth.error_reporting.queue', []);
        $pending = dispatch($job);

        // ShouldQueue does not imply the Queueable trait, and PendingDispatch
        // forwards these straight to the job. A host job that only implements
        // the interface must still be dispatchable, just without routing.
        if (method_exists($job, 'onConnection')
            && is_string($connection = ($queue['connection'] ?? null))
            && $connection !== '') {
            $pending->onConnection($connection);
        }

        if (method_exists($job, 'onQueue')
            && is_string($name = ($queue['queue'] ?? null))
            && $name !== '') {
            $pending->onQueue($name);
        }

        // Deferring to after the response keeps the failing request fast, but it
        // relies on the terminable middleware stack actually running — which it
        // does not under `queue:work` or in tests. Off by default for that reason.
        if ((bool) ($queue['after_response'] ?? false)) {
            $pending->afterResponse();
        }
    }

    private function shouldReport(FederatedAuthError $error): bool
    {
        if (! (bool) config('federated-auth.error_reporting.enabled', false)) {
            return false;
        }

        foreach ((array) config('federated-auth.error_reporting.ignore_exceptions', []) as $ignored) {
            if (is_string($ignored) && $error->exception instanceof $ignored) {
                return false;
            }
        }

        $only = (array) config('federated-auth.error_reporting.only_exceptions', []);

        if ($only === []) {
            return true;
        }

        foreach ($only as $wanted) {
            if (is_string($wanted) && $error->exception instanceof $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, mixed>
     */
    private function handlers(): array
    {
        $handlers = config('federated-auth.error_reporting.handlers', []);

        if ($handlers instanceof Closure || is_string($handlers)) {
            $handlers = [$handlers];
        }

        return is_array($handlers) ? array_values(array_filter($handlers)) : [];
    }

    /**
     * Last resort. If durable capture is broken, the file log is the only place
     * left that can say so — and losing the original authentication error
     * because the error logger failed would be the worst possible outcome.
     */
    private function fallback(string $message, Throwable $exception, FederatedAuthError $error): void
    {
        try {
            Log::critical($message, [
                'reporter_error' => $exception->getMessage(),
                'reporter_exception' => $exception::class,
                'original_exception' => $error->exception::class,
                'operation' => $error->operation,
                'provider' => $error->context->provider,
            ]);
        } catch (Throwable) {
            // Nothing left to try.
        }
    }
}
