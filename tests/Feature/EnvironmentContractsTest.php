<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Bases\ModelBase;
use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\ActorResolver;
use Obelaw\Ium\Contracts\Authorizer;
use Obelaw\Ium\Contracts\SettingsRepository;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;
use Obelaw\Ium\Exceptions\AuthorizationException;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleScope;
use RuntimeException;

final class CentralDomain extends Domain {}
final class TenantDomain extends Domain {}

final class BetaRecordModel extends ModelBase
{
    protected ?string $module = 'beta';
    protected $table = 'records';
    protected $fillable = ['code'];
}

final class InMemoryMultiTenantContext implements TenantContext
{
    private ?TenantIdentity $active = null;

    /**
     * @param list<TenantIdentity> $tenants
     */
    public function __construct(
        private readonly array $tenants,
    ) {}

    public function isMultiTenant(): bool
    {
        return true;
    }

    public function current(): ?TenantIdentity
    {
        return $this->active;
    }

    public function connectionName(): ?string
    {
        return $this->active?->connection;
    }

    public function all(): iterable
    {
        return $this->tenants;
    }

    public function find(string $identifier): ?TenantIdentity
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->id === $identifier || $tenant->key === $identifier) {
                return $tenant;
            }
        }

        return null;
    }

    public function runForTenant(TenantIdentity $tenant, callable $callback): mixed
    {
        $previous = $this->active;
        $this->active = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->active = $previous;
        }
    }

    public function enabledModulesFor(TenantIdentity $tenant): ?array
    {
        return $tenant->enabledModules;
    }
}

it('provides working zero-config Null defaults for all environment contracts', function () {
    $tenantContext = app(TenantContext::class);
    $actorResolver = app(ActorResolver::class);
    $authorizer = app(Authorizer::class);
    $settings = app(SettingsRepository::class);

    expect($tenantContext->isMultiTenant())->toBeFalse()
        ->and($tenantContext->current())->toBeNull()
        ->and($tenantContext->connectionName())->toBeNull()
        ->and($actorResolver->resolve()->type)->toBe('system')
        ->and($actorResolver->id())->toBe('system')
        ->and($authorizer->allows('accounting.journal.post'))->toBeTrue();

    $settings->set('accounting.currency', 'SAR');

    expect($settings->has('accounting.currency'))->toBeTrue()
        ->and($settings->get('accounting.currency'))->toBe('SAR');

    $settings->forget('accounting.currency');
    expect($settings->get('accounting.currency', 'USD'))->toBe('USD');
});

it('runs ium:tenants:migrate across two isolated tenant connections while php artisan migrate migrates central only', function () {
    config()->set('database.connections.tenant_a', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('database.connections.tenant_b', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $tenantA = new TenantIdentity(id: '1', key: 'acme', connection: 'tenant_a');
    $tenantB = new TenantIdentity(id: '2', key: 'globex', connection: 'tenant_b');

    $context = new InMemoryMultiTenantContext([$tenantA, $tenantB]);
    app()->instance(TenantContext::class, $context);

    $alphaPath = dirname(__DIR__) . '/Fixtures/migrations/alpha';
    $betaPath = dirname(__DIR__) . '/Fixtures/migrations/beta';

    Ium::registerModule(Module::make(
        name: 'alpha',
        domain: CentralDomain::class,
        scope: ModuleScope::Central,
        migrationPaths: $alphaPath,
    ));

    Ium::registerModule(Module::make(
        name: 'beta',
        domain: TenantDomain::class,
        scope: ModuleScope::Tenant,
        migrationPaths: $betaPath,
    ));

    // In multi-tenant mode, standard `migrate` runs CENTRAL modules only.
    $this->artisan('migrate')->assertExitCode(0);

    expect(Schema::connection('sqlite')->hasTable('ium_alpha_items'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('ium_beta_records'))->toBeFalse();

    // `ium:tenants:migrate` runs TENANT modules across both tenant connections.
    $this->artisan('ium:tenants:migrate')->assertExitCode(0);

    expect(Schema::connection('tenant_a')->hasTable('ium_beta_records'))->toBeTrue()
        ->and(Schema::connection('tenant_b')->hasTable('ium_beta_records'))->toBeTrue()
        ->and(Schema::connection('tenant_a')->hasTable('ium_alpha_items'))->toBeFalse()
        ->and(Schema::connection('tenant_b')->hasTable('ium_alpha_items'))->toBeFalse();
});

it('binds ModelBase and Ium::transaction() to the same active TenantContext connection with full rollback isolation', function () {
    config()->set('database.connections.tenant_a', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('database.connections.tenant_b', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $tenantA = new TenantIdentity(id: '1', key: 'acme', connection: 'tenant_a');
    $tenantB = new TenantIdentity(id: '2', key: 'globex', connection: 'tenant_b');

    $context = new InMemoryMultiTenantContext([$tenantA, $tenantB]);
    app()->instance(TenantContext::class, $context);

    $betaPath = dirname(__DIR__) . '/Fixtures/migrations/beta';
    Ium::registerModule(Module::make('beta', TenantDomain::class, ModuleScope::Tenant, migrationPaths: $betaPath));

    $this->artisan('ium:tenants:migrate')->assertExitCode(0);

    // Commit a record on tenant A.
    $context->runForTenant($tenantA, function (): void {
        Ium::transaction(function (): void {
            BetaRecordModel::create(['code' => 'A-001']);
        });
    });

    // Fail a transaction on tenant B — must roll back on tenant_b, not default sqlite!
    expect(fn () => $context->runForTenant($tenantB, function (): void {
        Ium::transaction(function (): void {
            BetaRecordModel::create(['code' => 'B-ROLLBACK']);
            throw new RuntimeException('Simulated domain failure');
        });
    }))->toThrow(RuntimeException::class);

    // Commit a valid record on tenant B.
    $context->runForTenant($tenantB, function (): void {
        Ium::transaction(function (): void {
            BetaRecordModel::create(['code' => 'B-001']);
        });
    });

    $codesA = $context->runForTenant($tenantA, fn () => BetaRecordModel::query()->pluck('code')->all());
    $codesB = $context->runForTenant($tenantB, fn () => BetaRecordModel::query()->pluck('code')->all());

    expect($codesA)->toBe(['A-001'])
        ->and($codesB)->toBe(['B-001']);
});

it('supports custom Authorizer enforcement and per-tenant SettingsRepository isolation', function () {
    $tenantA = new TenantIdentity(id: '10', key: 'alpha', connection: 'sqlite');
    $tenantB = new TenantIdentity(id: '20', key: 'beta', connection: 'sqlite');

    $context = new InMemoryMultiTenantContext([$tenantA, $tenantB]);
    app()->instance(TenantContext::class, $context);

    $settings = app(SettingsRepository::class);
    $context->runForTenant($tenantA, fn () => $settings->set('currency', 'SAR'));
    $context->runForTenant($tenantB, fn () => $settings->set('currency', 'EGP'));

    expect($context->runForTenant($tenantA, fn () => $settings->get('currency')))->toBe('SAR')
        ->and($context->runForTenant($tenantB, fn () => $settings->get('currency')))->toBe('EGP');

    $strictAuthorizer = new class implements Authorizer
    {
        public function allows(string $ability, mixed $subject = null, ?Actor $actor = null): bool
        {
            return $ability === 'allowed.action';
        }

        public function authorize(string $ability, mixed $subject = null, ?Actor $actor = null): void
        {
            if (! $this->allows($ability, $subject, $actor)) {
                throw AuthorizationException::denied($ability);
            }
        }
    };

    app()->instance(Authorizer::class, $strictAuthorizer);

    expect(Ium::authorizer()->allows('allowed.action'))->toBeTrue()
        ->and(fn () => Ium::authorizer()->authorize('forbidden.action'))
        ->toThrow(AuthorizationException::class);
});
