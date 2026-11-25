# Obelawium (IUM) Core Architecture & Module Conformance Standard — v1.0

**Status:** Approved (Phase 0 Baseline)  
**Version:** `1.0.0`  
**Applies To:** `obelaw/ium`, `obelaw/ium-tenancy`, `obelaw/twist`, and all `obelaw/ium-*` domain modules.

---

## 1. Architectural Topology & The Golden Rule

```text
obelaw/ium           ← The Standard: Contracts + Module Registry + Building Blocks + Conformance Suite
   ▲          ▲
ium-* modules   obelaw/ium-tenancy  ← Multi-Tenancy Engine (Database-per-Tenant)
                       ▲
                  obelaw/twist       ← Filament v5 Presentation Binding Only
```

### Non-Negotiable Rules
1. **Strict Dependency Isolation:** Domain modules (`obelaw/ium-*`) depend **only** on `obelaw/ium` (and pure PHP / `illuminate/*` packages). A domain module **MUST NOT** depend on `obelaw/ium-tenancy`, `obelaw/twist`, `filament/filament`, `livewire/livewire`, HTTP controllers, routes, Blade views, or `JsonResource`.
2. **Code Deletion Criterion:** Adopting the IUM v1.0 standard must **remove** duplicated boilerplate from modules (custom migration commands, manual `autoMigrate` flags, ad-hoc sequence counters, float money math, manual transaction/after-commit wrappers, and `HasDBTenancy`), never add more.
3. **Reference Pilot Validation:** `obelaw/ium-accounting` is the reference pilot module. Any contract or building block in `obelaw/ium` is considered production-ready only after `ium-accounting` migrates onto it and passes the `ModuleTestCase` conformance suite in both single-tenant and multi-tenant modes.

---

## 2. Phase 0 Normative Decisions

| # | Decision Topic | Binding v1.0 Specification |
|---|---|---|
| **D1** | **Multi-Tenancy Model** | **Database-per-Tenant ONLY** for `v1.0.0`. `TenantContext` exposes the active tenant identity and database connection name so single-DB (`tenant_id`) can be added in `v1.1+` without breaking module contracts. |
| **D2** | **Meaning of `ignoreMigrations()`** | **Option B (Total Ownership by Host App):** Calling `ignoreMigrations($module)` declares that the host application manages that module's schema. Both `php artisan migrate` and all `ium:*` migration commands (`ium:migrate`, `ium:tenants:migrate`) **skip** the module completely. |
| **D3** | **Location of `ium:tenants:migrate`** | Lives in **`obelaw/ium` core**, implemented purely against the `TenantContext` contract (and a tenant enumerator contract). `obelaw/ium-tenancy` supplies the concrete database-per-tenant driver. |
| **D4** | **Behavior of `php artisan migrate`** | **Single-tenant mode** (`TenantContext::isMultiTenant() === false`): loads all non-ignored modules. **Multi-tenant mode** (`TenantContext::isMultiTenant() === true`): loads **central** modules only (`ModuleScope::Central` and `ModuleScope::Both`); tenant-scoped modules are migrated exclusively via `ium:tenants:migrate`. |
| **D5** | **Per-Tenant Module Activation** | Supported inside `obelaw/ium-tenancy`. Modules remain unaware of activation state; `ium-tenancy` filters active modules per tenant (enforcing `ModuleRegistry` dependency order) during provisioning and runtime. |
| **D6** | **Supported Platform Matrix** | **PHP `^8.2`** (tested on 8.2, 8.3, 8.4), **Laravel `^11.0\|^12.0\|^13.0`**, **Filament `^5.0` ONLY** (for `obelaw/twist` and all `twist-*` packages). |
| **D7** | **Versioning & Dependency Policy** | Strict **SemVer (`^1.0`)**. Wildcard `"*"`, `"dev-main"`, `"minimum-stability": "dev"`, and local filesystem `path` repositories or cross-repo relative `autoload-dev` paths are **forbidden** in tagged releases. |

---

## 3. Core Standard Components (`obelaw/ium` v1.0)

### 3.1 Container Safety & Octane Compliance
- No mutable `static` singletons for runtime state (`ObelawiumManager`, `ObelawConfigManager`, `ModuleRegistry`).
- All core managers are bound in Laravel's Service Container and flushed cleanly between Octane requests and Pest test cases.
- Table prefixing in `ModelBase` and `MigrationBase` always normalizes module prefixes to `ium_<slug>_` (whether a module defines `$module = 'accounting'` or `$module = 'accounting_'`) and guards against double-prefixing.

### 3.2 Module Registry & Lifecycle (`Phase 2`)
- Every module registers a `Module` descriptor via `Ium::registerModule(Module $module)`:
  - `name` (`slug`, e.g. `'accounting'`)
  - `domain` (`class-string<Domain>`)
  - `scope` (`ModuleScope::Central | ModuleScope::Tenant | ModuleScope::Both`, default `ModuleScope::Tenant`)
  - `dependencies` (`list<string>` of required module slugs)
  - `migrationPaths` (`list<string>`)
- `ModuleRegistry` guarantees:
  - Duplicate registration prevention (`DuplicateModuleException`).
  - Missing dependency detection (`MissingModuleDependencyException`).
  - Circular dependency detection (`CircularModuleDependencyException`).
  - Deterministic topological ordering (`ordered()`).
- Unified core commands:
  - `php artisan ium:modules` — inspects registered modules, scopes, dependencies, and migration status.
  - `php artisan ium:migrate` — runs migrations in topological order for eligible modules (honoring `ignoreMigrations()` and `useMigrationPath()`).
- `Ium::registerDomain()` remains supported as a `@deprecated` compatibility shim during module migration.

### 3.3 Environment Contracts (`Phase 3`)
Modules consume runtime environment capabilities strictly through interfaces bound in the container, each backed by a zero-config `Null` default implementation in `obelaw/ium`:

| Contract | Responsibility | Null Default Behavior |
|---|---|---|
| `TenantContext` | Current `TenantIdentity`, active DB connection name, multi-tenant flag, `runForTenant()`, and tenant iteration for CLI commands | Single-tenant (`isMultiTenant() = false`, default DB connection, `current() = null`) |
| `TenantIdentity` | Immutable value object representing a tenant (`id`, `key`, `connection`, `metadata`) | N/A |
| `ActorResolver` | Resolves the current `Actor` (`type`, `id`, `name`) for audit & Maker-Checker | Returns `Actor::system()` (`type: 'system', id: 'system'`) |
| `Authorizer` | Domain-level authorization check (`allows()`, `authorize()`) throwing `AuthorizationException` | Permits all in headless/unconfigured mode (`NullAuthorizer`) |
| `SettingsRepository` | Scoped key/value settings lookup (`get()`, `set()`, `has()`, `forget()`) | Reads/writes in-memory + Laravel `config()` fallback |

- **`ModelBase` & Transactions:** `ModelBase::getConnectionName()` and `Action` / `Ium::transaction()` resolve the active connection from `TenantContext::connectionName()` (falling back to `obelawium.db.connection`, then default Laravel connection), guaranteeing models and transactions always share the exact same database connection.
- **`php artisan ium:tenants:migrate`:** Built in `obelaw/ium` core over `TenantContext`, migrating tenant-scoped modules (respecting `ignoreMigrations()` and custom paths) across all tenants returned by the context.

### 3.4 Standard Building Blocks (`Phase 4`)
1. **`Obelaw\Ium\Support\Money`:** Immutable minor-unit integer representation with ISO 4217 decimal precision (0, 2, 3 decimals), currency-checked arithmetic (`add`, `subtract`, `multiply`), major-unit conversion (`fromMajor`, `toMajor`, `format`), and penny-exact `allocate(array $ratios)` with zero rounding drift.
2. **`Obelaw\Ium\Support\Sequence`:** Concurrency-safe, tenant-isolated atomic sequence generator backed by `ium_sequences` (using `lockForUpdate()` on the active tenant connection) with customizable prefix, padding, and period reset scope.
3. **`Obelaw\Ium\Actions\Action`:** Standardized write-operation wrapper providing:
   - Domain authorization via `Authorizer`
   - Actor resolution via `ActorResolver`
   - Transaction execution on `TenantContext` connection
   - Structured audit callback hook
   - Automatic **`DB::afterCommit()`** domain event dispatching
4. **`Obelaw\Ium\Bases\BaseDto`:** Final strict `Dto` foundation supporting PHP 8.2+ `readonly` child properties, Laravel validation throwing `ValidationException`, and typed `from(array $data)` / `toArray()` contracts.
5. **`Obelaw\Ium\Outbox\Outbox`:** Transactional Outbox (`ium_outbox_messages`) written atomically inside domain transactions with `idempotency_key`, status lifecycle (`pending`, `processing`, `dispatched`, `failed`), retry/backoff tracking, and dispatcher command/service for reliable cross-domain integration.

### 3.5 Module Conformance Test Suite (`Phase 5`)
- `Obelaw\Ium\Testing\ModuleTestCase` extends Orchestra Testbench and executes module tests in both:
  - **Single-Tenant Mode** (default `NullTenantContext`)
  - **Dual-Tenant Isolation Mode** (two isolated SQLite tenant connections wired to an in-memory `TenantContext`)
- Built-in conformance assertions (`assertDataIsolated()`, `assertSequenceIsolated()`, `assertCacheAndSettingsIsolated()`, `assertModuleRegisteredCleanly()`) verify zero cross-tenant leakage automatically.

---

## 4. Twist & Presentation Boundary (`obelaw/twist` v1.0)
- Targets **Filament `^5.0` ONLY**.
- `TwistPanel::tenancy()` configures persistent tenant middleware and sets `scopeToTenant(false)`.
- `HasDBTenancy` is **deprecated and deleted**; Twist Resources never manage database connections directly.
- All business rules, invariants, and domain permissions live inside `ium-*` (`Action` + `Authorizer`); Twist is strictly a thin Filament v5 presentation layer delegating every mutation to `ium()`.
