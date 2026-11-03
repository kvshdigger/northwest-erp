<?php

use Webkul\Project\Services\ProgressFormula;

it('calculates precise decimal arithmetic and rounds only the final result', function (): void {
    $formula = new ProgressFormula;
    $tree = $formula->parse('(end - start) * width + .1 + .2', ['start' => 'Start', 'end' => 'End', 'width' => 'Width']);
    expect($formula->calculate($tree, ['start' => '0.1', 'end' => '1.1', 'width' => '0.3']))->toBe('0.600000')
        ->and($formula->calculate($formula->parse('1 / 3 * 3', []), []))->toBe('1.000000')
        ->and($formula->calculate($formula->parse('1 / 6', []), []))->toBe('0.166667');
});

it('validates syntax without substituting dummy values', function (): void {
    $formula = new ProgressFormula;
    $tree = $formula->parse('end / (width - start)', ['start' => 'Start', 'end' => 'End', 'width' => 'Width']);
    expect($formula->calculate($tree, ['start' => '1', 'end' => '8', 'width' => '3']))->toBe('4.000000');
    expect(fn () => $formula->calculate($tree, ['start' => '1', 'end' => '8', 'width' => '1']))->toThrow(InvalidArgumentException::class, 'Division by zero');
    expect(fn () => $formula->calculate($tree, ['end' => '8', 'width' => '1']))->toThrow(InvalidArgumentException::class, 'Missing numeric value');
});

it('rejects executable and malformed expressions', function (string $expression): void {
    expect(fn () => (new ProgressFormula)->parse($expression, ['value' => 'Value']))->toThrow(InvalidArgumentException::class);
})->with(['system(1)', 'value.__proto__', 'value ** 2', '1; exit', '1 2', '1e3', '(value + 1', 'value +', '1,2', 'unknown', '', '1'.str_repeat('+1', 101)]);

it('bounds formula length, constants and stored quantity range', function (): void {
    $formula = new ProgressFormula;
    expect(fn () => $formula->parse(str_repeat('1', 1001), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $formula->parse(str_repeat('1', 31), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $formula->calculate($formula->parse('0', []), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $formula->calculate($formula->parse('-1', []), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $formula->calculate($formula->parse('1000000000000000000', []), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $formula->calculate($formula->parse('.0000001', []), []))->toThrow(InvalidArgumentException::class);
});
