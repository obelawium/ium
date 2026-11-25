<?php

declare(strict_types=1);

namespace Obelaw\Ium\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Modules\ModuleRegistry;

class IumMigrateCommand extends Command
{
    protected $signature = 'ium:migrate
        {--module=* : Limit migrations to specific module slug(s)}
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--pretend : Dump the SQL queries that would be run}
        {--step : Force the migrations to be run so they can be rolled back individually}';

    protected $description = 'Run migrations for registered Obelawium (IUM) modules in topological order';

    public function handle(ModuleRegistry $registry, TenantContext $tenantContext): int
    {
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->option('module'), 'is_string'));
        $only = $requested === [] ? null : $requested;

        $paths = $registry->migrationPaths(
            multiTenant: $tenantContext->isMultiTenant(),
            only: $only,
        );

        if ($paths === []) {
            $this->info('No eligible Obelawium module migrations to run.');

            return self::SUCCESS;
        }

        $options = [
            '--path' => $paths,
            '--realpath' => true,
            '--force' => (bool) $this->option('force') || ! $this->laravel->isProduction(),
            '--pretend' => (bool) $this->option('pretend'),
            '--step' => (bool) $this->option('step'),
        ];

        if ($database = $this->option('database')) {
            $options['--database'] = (string) $database;
        }

        return $this->call('migrate', $options);
    }
}
