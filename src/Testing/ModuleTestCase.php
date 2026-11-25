<?php

declare(strict_types=1);

namespace Obelaw\Ium\Testing;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;
use Obelaw\Ium\Defaults\NullTenantContext;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Providers\ObelawiumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Conformance TestCase for all Obelawium (IUM) domain modules.
 *
 * Allows any domain module to execute its scenarios twice automatically:
 *   1. Single-tenant mode (zero-config `NullTenantContext`)
 *   2. Dual-tenant isolated mode (two independent SQLite tenant databases)
 *
 * Includes built-in conformance assertions for registration, data isolation,
 * sequence isolation, and cache/settings isolation.
 */
abstract class ModuleTestCase extends Orchestra
{
    protected TenantIdentity $tenantA;

    protected TenantIdentity $tenantB;

    protected InMemoryTenantContext $dualTenantContext;

    /**
     * Return the domain module's ServiceProvider class names to load alongside IUM core.
     *
     * @return list<class-string>
     */
    protected function moduleServiceProviders(): array
    {
        return [];
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_unique(array_merge(
            [ObelawiumServiceProvider::class],
            $this->moduleServiceProviders(),
        )));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.ium_tenant_1', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.ium_tenant_2', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = new TenantIdentity(id: '1', key: 'tenant-a', connection: 'ium_tenant_1');
        $this->tenantB = new TenantIdentity(id: '2', key: 'tenant-b', connection: 'ium_tenant_2');
        $this->dualTenantContext = new InMemoryTenantContext([$this->tenantA, $this->tenantB]);
    }

    /**
     * Boot single-tenant schema (fresh SQLite + `ium:migrate`) and run the callback.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    protected function runInSingleTenantMode(callable $callback): mixed
    {
        DB::purge('sqlite');
        $this->app->instance(TenantContext::class, new NullTenantContext());

        $exitCode = Artisan::call('ium:migrate', ['--database' => 'sqlite']);
        self::assertSame(0, $exitCode, 'Single-tenant `ium:migrate` failed: ' . Artisan::output());

        return $callback();
    }

    /**
     * Boot dual-tenant schema (central `migrate` + `ium:tenants:migrate` on both tenants)
     * and invoke `$callback($context, $tenantA, $tenantB)`.
     *
     * @template TResult
     *
     * @param callable(InMemoryTenantContext, TenantIdentity, TenantIdentity): TResult $callback
     *
     * @return TResult
     */
    protected function runInDualTenantMode(callable $callback): mixed
    {
        DB::purge('sqlite');
        DB::purge('ium_tenant_1');
        DB::purge('ium_tenant_2');

        $this->app->instance(TenantContext::class, $this->dualTenantContext);

        $centralExit = Artisan::call('migrate', ['--database' => 'sqlite']);
        self::assertSame(0, $centralExit, 'Central `migrate` failed: ' . Artisan::output());

        $tenantsExit = Artisan::call('ium:tenants:migrate');
        self::assertSame(0, $tenantsExit, 'Dual-tenant `ium:tenants:migrate` failed: ' . Artisan::output());

        return $callback($this->dualTenantContext, $this->tenantA, $this->tenantB);
    }

    /**
     * Execute a domain test scenario TWICE:
     *   1. In single-tenant mode (`mode === 'single'`, `tenant === null`)
     *   2. In dual-tenant mode inside BOTH Tenant A and Tenant B (`mode === 'tenant'`)
     *
     * @param callable(string, ?TenantIdentity): void $scenario
     */
    protected function runConformance(callable $scenario): void
    {
        $this->runInSingleTenantMode(static function () use ($scenario): void {
            $scenario('single', null);
        });

        $this->runInDualTenantMode(static function (
            InMemoryTenantContext $context,
            TenantIdentity $tenantA,
            TenantIdentity $tenantB,
        ) use ($scenario): void {
            $context->runForTenant($tenantA, static fn (TenantIdentity $t) => $scenario('tenant', $t));
            $context->runForTenant($tenantB, static fn (TenantIdentity $t) => $scenario('tenant', $t));
        });
    }

    /**
     * Assert that a module is properly registered in `ModuleRegistry`, has a valid
     * acyclic dependency graph, and resolves cleanly through `ium()->{$name}()`.
     */
    protected function assertModuleRegistered(string $name, ?string $expectedDomainClass = null): void
    {
        self::assertTrue(Ium::modules()->has($name), "Module [{$name}] is not registered in ModuleRegistry.");

        $ordered = Ium::modules()->ordered();
        $names = array_map(static fn ($m): string => $m->name, $ordered);
        self::assertContains($name, $names, "Module [{$name}] missing from topological ordering.");

        $domain = ium()->{$name}();
        self::assertNotNull($domain);

        if ($expectedDomainClass !== null) {
            self::assertInstanceOf($expectedDomainClass, $domain);
        }
    }

    /**
     * Assert strict database isolation between two tenants.
     *
     * @param callable(TenantIdentity): void $writeRecord Writes one record in the active tenant.
     * @param callable(TenantIdentity): int $countRecords Counts visible records in the active tenant.
     */
    protected function assertDataIsolated(callable $writeRecord, callable $countRecords): void
    {
        $this->runInDualTenantMode(static function (
            InMemoryTenantContext $context,
            TenantIdentity $tenantA,
            TenantIdentity $tenantB,
        ) use ($writeRecord, $countRecords): void {
            $context->runForTenant($tenantA, static fn (TenantIdentity $t) => $writeRecord($t));

            $countAAfterFirst = $context->runForTenant($tenantA, static fn (TenantIdentity $t): int => $countRecords($t));
            $countBAfterFirst = $context->runForTenant($tenantB, static fn (TenantIdentity $t): int => $countRecords($t));

            self::assertSame(1, $countAAfterFirst, 'Tenant A should see its own written record.');
            self::assertSame(0, $countBAfterFirst, 'Tenant B must NOT see records written in Tenant A.');

            $context->runForTenant($tenantB, static fn (TenantIdentity $t) => $writeRecord($t));
            $context->runForTenant($tenantB, static fn (TenantIdentity $t) => $writeRecord($t));

            $countAAfterSecond = $context->runForTenant($tenantA, static fn (TenantIdentity $t): int => $countRecords($t));
            $countBAfterSecond = $context->runForTenant($tenantB, static fn (TenantIdentity $t): int => $countRecords($t));

            self::assertSame(1, $countAAfterSecond, 'Tenant A record count must remain unaffected by Tenant B writes.');
            self::assertSame(2, $countBAfterSecond, 'Tenant B should see only its own 2 written records.');
        });
    }

    /**
     * Assert strict sequence counter isolation between two tenants.
     */
    protected function assertSequenceIsolated(string $sequenceKey, ?string $prefix = 'DOC'): void
    {
        $this->runInDualTenantMode(static function (
            InMemoryTenantContext $context,
            TenantIdentity $tenantA,
            TenantIdentity $tenantB,
        ) use ($sequenceKey, $prefix): void {
            $firstA = $context->runForTenant($tenantA, static fn (): string => Ium::sequence()->next($sequenceKey, $prefix, 4));
            $secondA = $context->runForTenant($tenantA, static fn (): string => Ium::sequence()->next($sequenceKey, $prefix, 4));
            $firstB = $context->runForTenant($tenantB, static fn (): string => Ium::sequence()->next($sequenceKey, $prefix, 4));

            $expectedFirst = $prefix ? "{$prefix}-0001" : '0001';
            $expectedSecond = $prefix ? "{$prefix}-0002" : '0002';

            self::assertSame($expectedFirst, $firstA);
            self::assertSame($expectedSecond, $secondA);
            self::assertSame($expectedFirst, $firstB, 'Tenant B sequence must start independently from 1.');
        });
    }

    /**
     * Assert strict Cache and SettingsRepository isolation between two tenants.
     */
    protected function assertCacheAndSettingsIsolated(string $key = 'conformance.isolation_probe'): void
    {
        $this->runInDualTenantMode(static function (
            InMemoryTenantContext $context,
            TenantIdentity $tenantA,
            TenantIdentity $tenantB,
        ) use ($key): void {
            $context->runForTenant($tenantA, static function () use ($key): void {
                Cache::put($key, 'cache-A', 60);
                Ium::settings()->set($key, 'setting-A');
            });

            $context->runForTenant($tenantB, static function () use ($key): void {
                self::assertNull(Cache::get($key), 'Tenant B must NOT read Tenant A cache entries.');
                self::assertNull(Ium::settings()->get($key), 'Tenant B must NOT read Tenant A settings.');

                Cache::put($key, 'cache-B', 60);
                Ium::settings()->set($key, 'setting-B');
            });

            $context->runForTenant($tenantA, static function () use ($key): void {
                self::assertSame('cache-A', Cache::get($key), 'Tenant A cache must remain intact after Tenant B writes.');
                self::assertSame('setting-A', Ium::settings()->get($key), 'Tenant A settings must remain intact.');
            });
        });
    }
}
