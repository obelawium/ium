# Obelawium (IUM) — Modular ERP Core Framework

![PHP](https://img.shields.io/badge/php-%5E8.2-8892BF)
![Laravel](https://img.shields.io/badge/laravel-%5E11.0%7C%5E12.0%7C%5E13.0-F9322C)

A decoupled, modular ERP framework for Laravel. Business logic is isolated from presentation — domains like EAM, PIM, and WMS are accessed through a single fluent API. No HTTP routes, JSON schemas, or frontend contracts. Pure backend domain orchestration.

## Core Philosophy: Fluent API DSL

```
ium() → domain() → service() → method()
```

| Segment | Role |
|---------|------|
| `ium()` | Global helper → `ObelawiumManager` singleton. Single entry point into the ERP domain mesh. |
| `domain()` | Macro-registered domain (`eam`, `pim`, `url`, `wms`). Resolves to its own manager, keeping domains isolated. |
| `service()` | Capability within the domain (`assets`, `categories`, `reports`, `products`). Returns a scoped service or sub-manager. |
| `method()` | Operation — terminal (`create`, `find`, `get`) materializes results; intermediate (`query`, `whereType`) returns `$this` for chaining. |

```php
$assets = ium()->eam()->assets()->available();
$products = ium()->pim()->products()->list();
$url = ium()->url()->shorten(ShortenUrlData::from([...]));
```

## Domain Registration

Domains register at boot via a macro on `ObelawiumManager`. Two patterns are supported:

```php
// Pattern A: Singleton (EAM-style) — resolve once, reuse across request lifecycle
$this->app->singleton('ium.eam', EamManager::class);
ObelawiumManager::macro('eam', fn () => app('ium.eam'));

// Pattern B: Fresh instance with config (PIM/URL-style) — new instance per call
ObelawiumManager::macro('pim', fn () => new PIMService($this->config()));
ObelawiumManager::macro('url', fn () => new UrlService($this->config()));
```

Laravel auto-discovers providers via `extra.laravel.providers` in `composer.json`.

## Domain Layout

Each domain follows a DDD-aligned structure with strict segregation between write mutations and read pipelines:

| Directory | Concern |
|-----------|---------|
| `Actions/` | Write mutations. Receives a validated DTO, performs one unit of work. |
| `Queries/` | Read pipelines. Intermediate methods return `$this`; terminal methods materialise results. |
| `Data/` | Immutable DTOs. Type-safe, structured input at every domain boundary. |
| `Models/` | Eloquent definitions. Persistence only — no business logic. Extends `ModelBase` (auto-prefixes `ium_` tables, binds configured connection). |
| `Managers/` | Domain entry points. Orchestrates sub-services (`AssetManager`, `CategoryManager`, `ReportsManager`). |
| `Events/` | Domain events fired during lifecycle transitions. |
| `Exceptions/` | Domain-specific exceptions for invalid states. |
| `Traits/` | Cross-cutting model behaviors (`HasAssets`, `HasMedia`, `HasStock`). |

```
src/
├── Actions/
├── Data/
├── Events/
├── Exceptions/
├── Managers/
├── Models/
├── Providers/
├── Queries/
└── Traits/
```

```php
class Asset extends ModelBase
{
    protected ?string $domain = 'eam';
    protected $fillable = ['code', 'name', 'status'];
}
```

## Available Domains

| Package | Domain | Description |
|---------|--------|-------------|
| `obelaw/ium-eam` | `eam` | Employee Asset Management — polymorphic asset lifecycle, transfers, categories, reports |
| `obelaw/ium-pim` | `pim` | Product Information Management — products, variants, pricing, categories, stock strategy |
| `obelaw/ium-url` | `url` | URL Shortening & Analytics — shortening, click tracking, aggregated stats |
| `obelaw/ium-wms` | `wms` | Warehouse Management — inventory, locations, movements |

## Usage

### Mutation

```php
use Obelaw\Ium\Eam\Data\AssetDTO;

$asset = ium()->eam()->assets()->create(AssetDTO::from([
    'code' => 'LPT-001',
    'name' => 'Dell Laptop',
    'category_id' => 1,
    'serial_number' => 'SN123456',
    'purchase_date' => '2024-01-15',
    'purchase_value' => 1200.00,
]));
```

`assets()->create()` builds an `AssetDTO` from the array, validates it, and dispatches `CreateAssetAction`. Persists through Eloquent, returns the `Asset` instance.

### Query Pipeline

```php
$assets = ium()->eam()
    ->assets()
    ->list();

$available = ium()->eam()
    ->assets()
    ->available();
```

### Output Control

Once `->query()` opens a pipeline, three patterns control what the terminal method returns:

#### Fluent Chain Pipeline

Intermediate methods return `$this`, deferring execution until a terminal method (`get`, `first`, `paginate`, `count`) is called:

```php
$results = ium()->xyz()
    ->records()
    ->query()
    ->whereType('premium')
    ->activeOnly()
    ->sortBy('created_at', 'desc')
    ->get();
```

#### Property / Field Selection

Constrain returned columns with `select()`, reducing memory at enterprise scale:

```php
$summaries = ium()->xyz()
    ->records()
    ->query()
    ->whereType('standard')
    ->select(['id', 'name', 'type'])
    ->paginate(25);
```

#### Output Formatter / Transformer

Reshape domain objects through a transformer before the terminal method returns:

```php
$results = ium()->xyz()
    ->records()
    ->query()
    ->whereType('premium')
    ->select(['id', 'name', 'metadata'])
    ->transform(XyzSummary::class)
    ->get();
```

### Cross-Domain Integration

Domains can delegate to each other through the shared `ium()` gateway:

```php
// PIM delegates stock queries to WMS when configured
$stock = ium()->pim()->stockManager()->getAvailable($productId);
```

## Testing

```bash
php artisan migrate --path=vendor/obelaw/ium-xyz/database/migrations
composer test
```

```php
it('creates and queries through the fluent pipeline', function () {
    $asset = ium()->eam()->assets()->create(AssetDTO::from([
        'code' => 'LPT-001',
        'name' => 'Test',
        'category_id' => 1,
    ]));

    $assets = ium()->eam()->assets()->available();

    expect($assets)->toHaveCount(1);
});
```

## Requirements

- PHP ^8.2
- Laravel ^11.0 | ^12.0 | ^13.0