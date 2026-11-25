# Obelawium (`obelaw/ium`) — Modular ERP Core Standard

![PHP](https://img.shields.io/badge/php-%5E8.2-8892BF)
![Laravel](https://img.shields.io/badge/laravel-%5E11.0%20%7C%20%5E12.0%20%7C%20%5E13.0-F9322C)
![License](https://img.shields.io/badge/license-MIT-green)

**Obelawium (`obelaw/ium`)** is the decoupled ERP core standard for Laravel. It defines the module registry, environment contracts, enterprise building blocks, and conformance test suite that power all `obelaw/ium-*` domain modules.

```text
obelaw/ium           ← The Standard: Contracts + ModuleRegistry + Building Blocks + Conformance Suite
   ▲          ▲
ium-* modules   obelaw/ium-tenancy  ← Multi-Tenancy Engine (Database-per-Tenant)
                       ▲
                  obelaw/twist       ← Filament v5 Presentation Binding Only
```

**The Golden Rule:** Domain modules depend **only** on `obelaw/ium`. They have zero knowledge of multi-tenancy drivers or presentation frameworks (Filament / Livewire / HTTP).

See the full normative specification in [docs/IUM-STANDARD.md](docs/IUM-STANDARD.md).

---

## Requirements

- **PHP** `^8.2`
- **Laravel** `^11.0 | ^12.0 | ^13.0`

## Installation

```bash
composer require obelaw/ium:^1.0
```

Laravel auto-discovers `Obelaw\Ium\Providers\ObelawiumServiceProvider`.

---

## 1. Module Registration & Migrations

Register each domain module with `Ium::registerModule()` using a `Module` descriptor:

```php
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleScope;

Ium::registerModule(Module::make(
    name: 'accounting',
    domain: AccountingDomain::class,
    scope: ModuleScope::Tenant,
    dependencies: [],
    migrationPaths: __DIR__ . '/../../database/migrations',
    version: '1.0.0',
));
```

`ModuleRegistry` enforces uniqueness (`DuplicateModuleException`), validates missing dependencies (`MissingModuleDependencyException`), detects cycles (`CircularModuleDependencyException`), and resolves deterministic topological order.

### Migration Overrides & Commands

```php
// Option B: host application owns this module's migrations; all migration commands skip it
Ium::ignoreMigrations('accounting');

// Override a module's migration directory
Ium::useMigrationPath('accounting', database_path('migrations/accounting'));
```

| Command | Behavior |
|---|---|
| `php artisan ium:modules` | Lists all registered modules in topological dependency order with scopes and migration status. |
| `php artisan ium:migrate` | Runs module migrations in topological order (all modules in single-tenant mode; central modules only in multi-tenant mode). |
| `php artisan ium:tenants:migrate` | Runs tenant-scoped migrations across tenants via `TenantContext` (supports `--tenant=` and `--module=`). |
| `php artisan migrate` | Automatically includes all non-ignored modules in single-tenant mode, or central modules only in multi-tenant mode. |
| `php artisan ium:outbox:process` | Dispatches pending transactional outbox messages (supports `--limit=` and `--retry-failed`). |

---

## 2. Persistence Base Classes

Extend `ModelBase` and `MigrationBase` to receive automatic normalized `ium_<module>_` table prefixing (whether `$module` is `'accounting'` or `'accounting_'`) and dynamic `TenantContext` connection resolution:

```php
use Obelaw\Ium\Bases\ModelBase;

class Account extends ModelBase
{
    protected ?string $module = 'accounting';
    protected $table = 'accounts'; // resolves to `ium_accounting_accounts`
}
```

Use `Ium::transaction(fn () => ...)` so transactions always execute on the exact same database connection resolved by `ModelBase`.

---

## 3. Environment Contracts

All runtime integrations are injected through container contracts with zero-configuration `Null` defaults:

- **`Obelaw\Ium\Contracts\TenantContext` & `TenantIdentity`**: Active tenant identity, database connection name, and `runForTenant()`.
- **`Obelaw\Ium\Contracts\ActorResolver` & `Actor`**: Current domain actor (`system` by default).
- **`Obelaw\Ium\Contracts\Authorizer`**: Domain-level permission checks throwing `AuthorizationException`.
- **`Obelaw\Ium\Contracts\SettingsRepository`**: Tenant-isolated settings with Laravel `config()` fallback.

---

## 4. Enterprise Building Blocks

### `Money` (Minor Units + Zero-Drift Allocation)
```php
use Obelaw\Ium\Support\Money;

$total = Money::fromMajor('100.50', 'USD'); // 10050 minor units
$kwd = Money::fromMajor('10.125', 'KWD');   // 10125 minor units (3 ISO decimals)
$parts = Money::minor(100, 'USD')->split(3); // [34, 33, 33] — exact sum = 100
```

### `Sequence` (Atomic & Tenant-Isolated)
```php
use Obelaw\Ium\Facades\Ium;

$voucherNo = Ium::sequence()->next('journal:2026', prefix: 'JE-2026', padding: 6);
// => 'JE-2026-000001'
```

### `Action` (Authorized, Transactional, After-Commit Events)
```php
use Obelaw\Ium\Actions\Action;
use Obelaw\Ium\Contracts\Actor;

final class PostJournalEntryAction extends Action
{
    protected ?string $ability = 'accounting.journal.post';

    protected function handle(mixed $input, Actor $actor): mixed
    {
        // Runs inside Ium::transaction() on the active tenant connection
        $this->recordEvent(new JournalEntryPosted($entry));
        return $entry;
    }
}
```

### `BaseDto` / `Dto` (Validated Readonly DTOs)
Supports both array-hydrated and promoted-constructor `readonly` subclasses, throwing `Illuminate\Validation\ValidationException` on invalid input, with `from()`, `toArray()`, `with()`, `only()`, and `except()`.

### `Outbox` (Transactional Cross-Domain Events)
```php
use Obelaw\Ium\Facades\Ium;

Ium::outbox()->publish(
    topic: 'sales.order.confirmed',
    payload: ['order_id' => 101],
    idempotencyKey: 'sales-order-confirmed-101',
);
```

---

## 5. Module Conformance Suite (`ModuleTestCase`)

Extend `Obelaw\Ium\Testing\ModuleTestCase` in any `obelaw/ium-*` test suite to automatically verify your module in **both single-tenant and dual-tenant modes**:

```php
use Obelaw\Ium\Testing\ModuleTestCase;

final class AccountingConformanceTest extends ModuleTestCase
{
    protected function moduleServiceProviders(): array
    {
        return [ObelawAccountingServiceProvider::class];
    }

    public function test_conformance(): void
    {
        $this->assertModuleRegistered('accounting');
        $this->runConformance(function (string $mode, ?TenantIdentity $tenant): void {
            // Executed automatically in single-tenant AND isolated tenant A & tenant B
        });
        $this->assertSequenceIsolated('journal:2026', 'JE');
        $this->assertCacheAndSettingsIsolated();
    }
}
```

---

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
