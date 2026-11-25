<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Feature;

use Obelaw\Ium\Engine\ObelawConfigManager;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\ObelawiumManager;

it('boots ObelawiumServiceProvider cleanly with valid container bindings and helpers', function () {
    expect(app()->bound(ObelawConfigManager::class))->toBeTrue()
        ->and(app()->bound(ObelawiumManager::class))->toBeTrue()
        ->and(app(Ium::class))->toBeInstanceOf(ObelawiumManager::class)
        ->and(ium())->toBe(app(ObelawiumManager::class));

    ium_set_config('core.test', 'ok', global: true);

    expect(ium_config('core.test'))->toBe('ok');
});
