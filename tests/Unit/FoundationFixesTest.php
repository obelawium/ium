<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Bases\BaseDto;
use Obelaw\Ium\Bases\MigrationBase;
use Obelaw\Ium\Bases\ModelBase;
use Obelaw\Ium\Engine\GlobalConfigManager;
use Obelaw\Ium\Engine\ObelawConfigManager;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\ObelawiumManager;

enum SampleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
}

final readonly class ArrayHydratedDto extends BaseDto
{
    public string $code;
    public string $name;
    public SampleStatus $status;

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string'],
            'status' => ['required'],
        ];
    }
}

final readonly class PromotedChildDto extends BaseDto
{
    public function __construct(
        public string $code,
        public int $quantity = 1,
        public SampleStatus $status = SampleStatus::Draft,
    ) {
        $this->validateSelf();
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:draft,active'],
        ];
    }
}

final class SampleDomain extends Domain
{
    public function readConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }
}

final class NoUnderscoreModel extends ModelBase
{
    protected ?string $module = 'eam';
    protected $table = 'assets';
}

final class TrailingUnderscoreModel extends ModelBase
{
    protected ?string $module = 'accounting_';
    protected $table = 'accounts';
}

final class RootModuleModel extends ModelBase
{
    protected $table = 'settings';
}

final class NoUnderscoreMigration extends MigrationBase
{
    protected ?string $module = 'eam';

    public function exposedPrefix(): string
    {
        return $this->prefix;
    }
}

final class TrailingUnderscoreMigration extends MigrationBase
{
    protected ?string $module = 'accounting_';

    public function exposedPrefix(): string
    {
        return $this->prefix;
    }
}

it('hydrates readonly child DTOs from arrays and coerces backed enums', function () {
    $dto = new ArrayHydratedDto([
        'code' => 'ACC-01',
        'name' => 'Cash',
        'status' => 'active',
    ]);

    expect($dto->code)->toBe('ACC-01')
        ->and($dto->name)->toBe('Cash')
        ->and($dto->status)->toBe(SampleStatus::Active)
        ->and($dto->toArray())->toBe([
            'code' => 'ACC-01',
            'name' => 'Cash',
            'status' => SampleStatus::Active,
        ]);
});

it('validates promoted readonly child DTOs via constructor and BaseDto::from()', function () {
    $dto = PromotedChildDto::from([
        'code' => 'INV-100',
        'quantity' => 5,
        'status' => 'active',
    ]);

    expect($dto->code)->toBe('INV-100')
        ->and($dto->quantity)->toBe(5)
        ->and($dto->status)->toBe(SampleStatus::Active);

    expect(fn () => new PromotedChildDto(code: 'A', quantity: 0))
        ->toThrow(ValidationException::class);

    expect(fn () => ArrayHydratedDto::from(['code' => '', 'name' => '']))
        ->toThrow(ValidationException::class);
});

it('exposes Domain::$config to subclasses via protected property and config() getter', function () {
    Ium::registerDomain('sample', SampleDomain::class);
    ium(['sample.flag' => 'enabled']);

    $domain = ium()->sample();

    expect($domain)->toBeInstanceOf(SampleDomain::class)
        ->and($domain->readConfigValue('sample.flag'))->toBe('enabled')
        ->and($domain->config()->get('sample.flag'))->toBe('enabled');
});

it('binds Ium::class, ium alias, and ObelawiumManager::class to the same container singleton', function () {
    $fromManager = app(ObelawiumManager::class);
    $fromAlias = app('ium');
    $fromFacadeClass = app(Ium::class);
    $fromHelper = ium();
    $fromStaticCompat = ObelawiumManager::getInstance();

    expect($fromAlias)->toBe($fromManager)
        ->and($fromFacadeClass)->toBe($fromManager)
        ->and($fromHelper)->toBe($fromManager)
        ->and($fromStaticCompat)->toBe($fromManager);
});

it('normalizes table prefixes with a trailing underscore in ModelBase and MigrationBase without double-prefixing', function () {
    $noUnderscore = new NoUnderscoreModel();
    $trailingUnderscore = new TrailingUnderscoreModel();
    $root = new RootModuleModel();

    expect($noUnderscore->getTable())->toBe('ium_eam_assets')
        ->and($trailingUnderscore->getTable())->toBe('ium_accounting_accounts')
        ->and($root->getTable())->toBe('ium_settings')
        ->and($noUnderscore->newInstance()->getTable())->toBe('ium_eam_assets');

    expect((new NoUnderscoreMigration())->exposedPrefix())->toBe('ium_eam_')
        ->and((new TrailingUnderscoreMigration())->exposedPrefix())->toBe('ium_accounting_');
});

it('isolates container-managed singletons and GlobalConfigManager across containers for Octane safety', function () {
    $originalContainer = Container::getInstance();

    try {
        $containerA = new Container();
        Container::setInstance($containerA);
        $managerA = ObelawiumManager::getInstance();
        $managerA->config()->set('tenant.marker', 'A', global: true);
        $managerA->registerDomain('sample', SampleDomain::class);

        $containerB = new Container();
        Container::setInstance($containerB);
        $managerB = ObelawiumManager::getInstance();

        expect($managerB)->not->toBe($managerA)
            ->and($managerB->config())->not->toBe($managerA->config())
            ->and($managerB->hasDomain('sample'))->toBeFalse()
            ->and($managerB->config()->get('tenant.marker'))->toBeNull()
            ->and(GlobalConfigManager::get('tenant.marker'))->toBeNull();
    } finally {
        Container::setInstance($originalContainer);
    }
});
