<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Exceptions\CircularModuleDependencyException;
use Obelaw\Ium\Exceptions\DuplicateModuleException;
use Obelaw\Ium\Exceptions\MissingModuleDependencyException;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleScope;

final class AlphaDomain extends Domain {}
final class BetaDomain extends Domain {}
final class GammaDomain extends Domain {}

it('prevents duplicate module registration and resolves dependencies in topological order', function () {
    Ium::registerModule(Module::make(
        name: 'gamma',
        domain: GammaDomain::class,
        dependencies: ['beta'],
    ));

    Ium::registerModule(Module::make(
        name: 'beta',
        domain: BetaDomain::class,
        dependencies: ['alpha'],
    ));

    Ium::registerModule(Module::make(
        name: 'alpha',
        domain: AlphaDomain::class,
    ));

    $orderedNames = array_map(
        static fn (Module $m): string => $m->name,
        Ium::modules()->ordered()
    );

    expect($orderedNames)->toBe(['alpha', 'beta', 'gamma'])
        ->and(ium()->alpha())->toBeInstanceOf(AlphaDomain::class)
        ->and(ium()->beta())->toBeInstanceOf(BetaDomain::class);

    expect(fn () => Ium::registerModule(Module::make('alpha', AlphaDomain::class)))
        ->toThrow(DuplicateModuleException::class);
});

it('detects missing and circular module dependencies', function () {
    Ium::registerModule(Module::make(
        name: 'beta',
        domain: BetaDomain::class,
        dependencies: ['nonexistent'],
    ));

    expect(fn () => Ium::modules()->ordered())
        ->toThrow(MissingModuleDependencyException::class);

    Ium::reset();

    Ium::registerModule(Module::make('alpha', AlphaDomain::class, dependencies: ['beta']));
    Ium::registerModule(Module::make('beta', BetaDomain::class, dependencies: ['gamma']));
    Ium::registerModule(Module::make('gamma', GammaDomain::class, dependencies: ['alpha']));

    expect(fn () => Ium::modules()->ordered())
        ->toThrow(CircularModuleDependencyException::class, 'alpha -> beta -> gamma -> alpha');
});

it('migrates registered module tables via ium:migrate and lists them via ium:modules', function () {
    $alphaPath = dirname(__DIR__) . '/Fixtures/migrations/alpha';
    $betaPath = dirname(__DIR__) . '/Fixtures/migrations/beta';

    Ium::registerModule(Module::make(
        name: 'beta',
        domain: BetaDomain::class,
        scope: ModuleScope::Tenant,
        dependencies: ['alpha'],
        migrationPaths: $betaPath,
    ));

    Ium::registerModule(Module::make(
        name: 'alpha',
        domain: AlphaDomain::class,
        scope: ModuleScope::Central,
        migrationPaths: $alphaPath,
    ));

    $exitCode = \Illuminate\Support\Facades\Artisan::call('ium:modules');
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('alpha')
        ->and($output)->toContain('beta')
        ->and(strpos($output, '| 1 | alpha'))->toBeLessThan(strpos($output, '| 2 | beta'));

    $this->artisan('ium:migrate')->assertExitCode(0);

    expect(Schema::hasTable('ium_alpha_items'))->toBeTrue()
        ->and(Schema::hasTable('ium_beta_records'))->toBeTrue();
});

it('migrates registered module tables via standard php artisan migrate', function () {
    $alphaPath = dirname(__DIR__) . '/Fixtures/migrations/alpha';

    Ium::registerModule(Module::make(
        name: 'alpha',
        domain: AlphaDomain::class,
        migrationPaths: $alphaPath,
    ));

    $this->artisan('migrate')->assertExitCode(0);

    expect(Schema::hasTable('ium_alpha_items'))->toBeTrue();
});

it('honors ignoreMigrations (Option B) and useMigrationPath across both ium:migrate and migrate', function () {
    $alphaPath = dirname(__DIR__) . '/Fixtures/migrations/alpha';
    $alphaCustomPath = dirname(__DIR__) . '/Fixtures/migrations/alpha_custom';
    $betaPath = dirname(__DIR__) . '/Fixtures/migrations/beta';

    Ium::registerModule(Module::make('alpha', AlphaDomain::class, migrationPaths: $alphaPath));
    Ium::registerModule(Module::make('beta', BetaDomain::class, migrationPaths: $betaPath));

    Ium::useMigrationPath('alpha', $alphaCustomPath);
    Ium::ignoreMigrations('beta');

    $this->artisan('ium:migrate')->assertExitCode(0);

    expect(Schema::hasTable('ium_alpha_custom_items'))->toBeTrue()
        ->and(Schema::hasTable('ium_alpha_items'))->toBeFalse()
        ->and(Schema::hasTable('ium_beta_records'))->toBeFalse();

    $this->artisan('migrate')->assertExitCode(0);

    expect(Schema::hasTable('ium_beta_records'))->toBeFalse();
});
