# Changelog

All notable changes to `obelaw/ium` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-25

### Added
- **Phase 0 Standard Specification:** Added normative specification in `docs/IUM-STANDARD.md`.
- **Module Registry (`Phase 2`):** Added `Module`, `ModuleScope`, `ModuleRegistry`, `Ium::registerModule()`, `Ium::ignoreMigrations()` (Option B), `Ium::useMigrationPath()`, topological dependency sorting, circular/missing/duplicate module exceptions, and Artisan commands `ium:modules` and `ium:migrate`.
- **Environment Contracts (`Phase 3`):** Added `TenantContext`, `TenantIdentity`, `Actor`, `ActorResolver`, `Authorizer`, `SettingsRepository`, zero-config `Null*` defaults, dynamic connection resolution in `ModelBase` & `Ium::transaction()`, and `ium:tenants:migrate`.
- **Building Blocks (`Phase 4`):**
  - `Obelaw\Ium\Support\Money` (minor-unit ISO 4217 arithmetic + zero-drift Largest-Remainder `allocate()` / `split()`).
  - `Obelaw\Ium\Support\Sequence` (`ium_sequences` atomic concurrency-safe, tenant-isolated numbering).
  - `Obelaw\Ium\Actions\Action` (authorization, actor resolution, active-connection transaction, audit hook, `afterCommit()` event dispatch).
  - `Obelaw\Ium\Bases\Dto` + `with()`, `only()`, `except()` helpers on `BaseDto`.
  - `Obelaw\Ium\Outbox\Outbox` (`ium_outbox_messages` transactional outbox with idempotency keys, exponential retry backoff, and `ium:outbox:process`).
- **Conformance Suite (`Phase 5`):** Added `Obelaw\Ium\Testing\ModuleTestCase` and `InMemoryTenantContext` for automated single-tenant + dual-tenant isolation verification.
- **CI & Packaging:** Added `LICENSE` (MIT), `.gitattributes`, `phpunit.xml`, Pest + Orchestra Testbench test suite, and GitHub Actions CI matrix (PHP 8.2–8.4 × Laravel 11–13).

### Fixed
- Fixed `BaseDto` readonly property hydration across PHP 8.2–8.4 child classes and switched validation failure to throw `Illuminate\Validation\ValidationException`.
- Changed `Domain::$config` from `private` to `protected readonly` and exposed `Domain::config()`.
- Fixed broken `ObelawiumServiceProvider` `Ium::class` binding (`new ObelawiumManager()` on private constructor) by aliasing `Ium::class` and `'ium'` to container-bound `ObelawiumManager::class`.
- Fixed missing trailing underscore in `ModelBase` and `MigrationBase` module table prefixes (`$module = 'eam'` → `ium_eam_`) and added double-prefix protection.
- Replaced process-global static singletons (`ObelawiumManager`, `ObelawConfigManager`, `GlobalConfigManager`) with container-scoped bindings for Laravel Octane and test isolation safety.
- Updated `README.md` to accurately reflect implemented APIs and removed undocumented/unimplemented references.
