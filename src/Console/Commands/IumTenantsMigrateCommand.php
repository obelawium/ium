<?php

declare(strict_types=1);

namespace Obelaw\Ium\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;
use Obelaw\Ium\Modules\ModuleRegistry;

class IumTenantsMigrateCommand extends Command
{
    protected $signature = 'ium:tenants:migrate
        {--tenant=* : Limit migrations to specific tenant ID(s) or key(s)}
        {--module=* : Limit migrations to specific module slug(s)}
        {--force : Force the operation to run when in production}
        {--pretend : Dump the SQL queries that would be run}
        {--step : Force the migrations to be run so they can be rolled back individually}';

    protected $description = 'Run tenant-scoped Obelawium (IUM) module migrations across tenants via TenantContext';

    public function handle(TenantContext $tenantContext, ModuleRegistry $registry): int
    {
        /** @var list<string> $tenantFilters */
        $tenantFilters = array_values(array_filter((array) $this->option('tenant'), 'is_string'));

        /** @var list<string> $moduleFilters */
        $moduleFilters = array_values(array_filter((array) $this->option('module'), 'is_string'));

        $tenants = $this->resolveTenants($tenantContext, $tenantFilters);

        if ($tenants === []) {
            $this->info('No tenants found to migrate.');

            return self::SUCCESS;
        }

        $overallExit = self::SUCCESS;

        foreach ($tenants as $tenant) {
            $exitCode = $tenantContext->runForTenant($tenant, function (TenantIdentity $activeTenant) use ($tenantContext, $registry, $moduleFilters): int {
                $enabledModules = $tenantContext->enabledModulesFor($activeTenant);

                $only = $this->resolveEffectiveModules($moduleFilters, $enabledModules);

                if ($only !== null && $only === []) {
                    $this->line("Skipping tenant [{$activeTenant->key}]: no active tenant modules.");

                    return self::SUCCESS;
                }

                $paths = $registry->tenantMigrationPaths($only);

                if ($paths === []) {
                    $this->line("Skipping tenant [{$activeTenant->key}]: no eligible migrations.");

                    return self::SUCCESS;
                }

                $options = [
                    '--path' => $paths,
                    '--realpath' => true,
                    '--force' => (bool) $this->option('force') || ! $this->laravel->isProduction(),
                    '--pretend' => (bool) $this->option('pretend'),
                    '--step' => (bool) $this->option('step'),
                ];

                $connection = $tenantContext->connectionName();

                if ($connection !== null && $connection !== '') {
                    $options['--database'] = $connection;
                }

                $this->info("Migrating tenant [{$activeTenant->key}] (connection: " . ($connection ?? 'default') . ')...');

                return $this->call('migrate', $options);
            });

            if ($exitCode !== self::SUCCESS) {
                $overallExit = $exitCode;
            }
        }

        return $overallExit;
    }

    /**
     * @param list<string> $filters
     *
     * @return list<TenantIdentity>
     */
    private function resolveTenants(TenantContext $tenantContext, array $filters): array
    {
        if ($filters !== []) {
            $resolved = [];

            foreach ($filters as $identifier) {
                $tenant = $tenantContext->find($identifier);

                if ($tenant !== null) {
                    $resolved[] = $tenant;
                } else {
                    $this->warn("Tenant [{$identifier}] not found.");
                }
            }

            return $resolved;
        }

        $all = [];

        foreach ($tenantContext->all() as $tenant) {
            $all[] = $tenant;
        }

        return $all;
    }

    /**
     * Intersect CLI `--module` filters with the tenant's enabled modules.
     *
     * @param list<string> $moduleFilters
     * @param list<string>|null $enabledModules
     *
     * @return list<string>|null
     */
    private function resolveEffectiveModules(array $moduleFilters, ?array $enabledModules): ?array
    {
        if ($moduleFilters === [] && $enabledModules === null) {
            return null;
        }

        if ($moduleFilters === []) {
            return $enabledModules;
        }

        if ($enabledModules === null) {
            return $moduleFilters;
        }

        return array_values(array_intersect($moduleFilters, $enabledModules));
    }
}
