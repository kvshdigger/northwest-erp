<?php

use App\Services\DefaultFinanceUnits;
use Webkul\Support\Enums\UOMType;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';

it('initializes an empty unit catalogue and can run repeatedly without duplicates', function () {
    UOM::query()->forceDelete();
    app(DefaultFinanceUnits::class)->ensure();
    expect(UOM::count())->toBe(6);
    $ids = UOM::pluck('id', 'name')->all();
    expect(array_keys($ids))->toContain('Units', 'm', 'm²', 'm³', 'kg', 'LS');
    app(DefaultFinanceUnits::class)->ensure();
    expect(UOM::pluck('id', 'name')->all())->toBe($ids);
    expect(UOM::where('name', 'm')->first()->type)->toBe(UOMType::REFERENCE);
});

it('preserves existing unit conversions and does not restore retired units', function () {
    $unit = UOM::firstOrFail();
    $unit->update(['rounding'=>0.5]);
    $before = $unit->refresh()->getAttributes();
    app(DefaultFinanceUnits::class)->ensure();
    expect($unit->fresh()->getAttributes())->toBe($before);
    $ls = UOM::where('name', 'LS')->firstOrFail();
    UOM::withoutEvents(fn () => $ls->delete());
    app(DefaultFinanceUnits::class)->ensure();
    expect(UOM::where('name', 'LS')->exists())->toBeFalse();
});
