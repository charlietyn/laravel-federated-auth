<?php

namespace Ronu\LaravelFederatedAuth\Console;

use Illuminate\Console\Command;

/**
 * Runs only the ronu/laravel-federated-auth migrations, in isolation from the
 * host application's own migrations.
 *
 * The package ships its migrations inside the package directory (never
 * auto-loaded and, by default, not published), so `php artisan migrate` in the
 * host app does NOT touch them. This command targets the package migration
 * directory explicitly via --path, letting a host provision (or roll back)
 * the identity store without running any of its own pending migrations.
 */
class MigrateCommand extends Command
{
    protected $signature = 'federated-auth:migrate
        {--rollback : Roll back the package migrations instead of running them}
        {--refresh : Roll back and re-run only the package migrations}
        {--status : Show the status of the package migrations}
        {--database= : The database connection to use (defaults to identity_store.connection)}
        {--force : Force the operation to run when in production}
        {--pretend : Dump the SQL queries that would be run}';

    protected $description = 'Run the federated-auth identity-store migrations in isolation from the host application migrations.';

    public function handle(): int
    {
        $path = realpath(__DIR__.'/../../database/migrations');

        if ($path === false) {
            $this->components->error('Could not locate the federated-auth migrations directory.');

            return self::FAILURE;
        }

        // The identity-store migration binds its own connection via
        // config('federated-auth.identity_store.connection'); mirror it here so
        // the migration repository (the `migrations` table) lives on the same
        // connection unless the caller overrides it explicitly.
        $connection = $this->option('database')
            ?: config('federated-auth.identity_store.connection');

        $arguments = array_filter([
            '--path' => $path,
            '--realpath' => true,
            '--database' => $connection,
            '--force' => (bool) $this->option('force'),
            '--pretend' => (bool) $this->option('pretend'),
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        if ($this->option('status')) {
            return $this->call('migrate:status', array_filter([
                '--path' => $path,
                '--realpath' => true,
                '--database' => $connection,
            ], static fn ($value) => $value !== null && $value !== false && $value !== ''));
        }

        $command = match (true) {
            (bool) $this->option('refresh') => 'migrate:refresh',
            (bool) $this->option('rollback') => 'migrate:rollback',
            default => 'migrate',
        };

        return $this->call($command, $arguments);
    }
}
