<?php

declare(strict_types=1);

namespace Obelaw\Ium\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Ium\Modules\ModuleRegistry;

class IumModulesCommand extends Command
{
    protected $signature = 'ium:modules';

    protected $description = 'List all registered Obelawium (IUM) modules in topological dependency order';

    public function handle(ModuleRegistry $registry): int
    {
        $modules = $registry->ordered();

        if ($modules === []) {
            $this->info('No Obelawium modules are currently registered.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($modules as $index => $module) {
            $ignored = $registry->isIgnoringMigrations($module->name);
            $paths = $registry->migrationPathsFor($module->name);

            $rows[] = [
                'order' => $index + 1,
                'name' => $module->name,
                'scope' => $module->scope->value,
                'domain' => $module->domain,
                'dependencies' => $module->dependencies === [] ? '-' : implode(', ', $module->dependencies),
                'migrations' => $ignored ? 'ignored (host-managed)' : (string) count($paths) . ' path(s)',
            ];
        }

        $this->table(
            ['#', 'Module', 'Scope', 'Domain', 'Dependencies', 'Migrations'],
            $rows
        );

        return self::SUCCESS;
    }
}
