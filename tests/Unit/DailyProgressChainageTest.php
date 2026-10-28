<?php

use App\Models\DailyProgressEntry;
use InvalidArgumentException;

it('converts standard chainage notation to metres', function (string $chainage, float $expected): void {
    expect(DailyProgressEntry::parseChainage($chainage))->toBe($expected);
})->with([
    ['0+000', 0.0],
    ['0+100', 100.0],
    ['1+250', 1250.0],
    ['2 + 015.5', 2015.5],
    ['150.25', 150.25],
]);

it('rejects invalid or negative chainage', function (string $chainage): void {
    expect(fn () => DailyProgressEntry::parseChainage($chainage))->toThrow(InvalidArgumentException::class);
})->with([
    [''],
    ['abc'],
    ['-1'],
    ['0+1000'],
    ['1+'],
]);

it('rejects offsets of one thousand metres or more', function (): void {
    expect(fn () => DailyProgressEntry::parseChainage('0+1000'))
        ->toThrow(InvalidArgumentException::class);
});
