<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Bases\ModelBase;
use Obelaw\Ium\Contracts\TenantIdentity;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleScope;
use Obelaw\Ium\Testing\ModuleTestCase;

final class ConformanceBetaDomain extends Domain
{
    public function createRecord(string $code): ConformanceBetaRecord
    {
        return Ium::transaction(static fn (): ConformanceBetaRecord => ConformanceBetaRecord::create([
            'code' => $code,
        ]));
    }

    public function countRecords(): int
    {
        return ConformanceBetaRecord::query()->count();
    }
}

final class ConformanceBetaRecord extends ModelBase
{
    protected ?string $module = 'beta';

    protected $table = 'records';

    protected $fillable = ['code'];
}

final class ConformanceBetaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Ium::registerModule(Module::make(
            name: 'beta',
            domain: ConformanceBetaDomain::class,
            scope: ModuleScope::Tenant,
            migrationPaths: dirname(__DIR__) . '/Fixtures/migrations/beta',
        ));
    }
}

final class ModuleConformanceSuiteTest extends ModuleTestCase
{
    protected function moduleServiceProviders(): array
    {
        return [
            ConformanceBetaServiceProvider::class,
        ];
    }

    public function test_module_passes_full_single_and_dual_tenant_conformance_suite(): void
    {
        $this->assertModuleRegistered('beta', ConformanceBetaDomain::class);

        $executions = [];

        $this->runConformance(function (string $mode, ?TenantIdentity $tenant) use (&$executions): void {
            $code = Ium::sequence()->next('beta:record', 'REC', 4);
            $record = ium()->beta()->createRecord($code);

            self::assertSame('REC-0001', $record->code);
            self::assertSame(1, ium()->beta()->countRecords());

            $executions[] = $mode . ':' . ($tenant?->key ?? 'single');
        });

        self::assertSame(['single:single', 'tenant:tenant-a', 'tenant:tenant-b'], $executions);

        $this->assertDataIsolated(
            writeRecord: static function (TenantIdentity $tenant): void {
                $code = Ium::sequence()->next('beta:iso', 'ISO', 4);
                ium()->beta()->createRecord("{$tenant->key}-{$code}");
            },
            countRecords: static fn (): int => ium()->beta()->countRecords(),
        );

        $this->assertSequenceIsolated('beta:voucher', 'VCH');
        $this->assertCacheAndSettingsIsolated();
    }
}
