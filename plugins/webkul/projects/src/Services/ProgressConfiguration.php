<?php

namespace Webkul\Project\Services;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ProgressConfiguration
{
    public function __construct(private ProgressFormula $formula) {}

    public function validate(array $definition): array
    {
        $definition = Validator::make($definition, [
            'name'              => ['required', 'string', 'max:120'],
            'unit'              => ['nullable', 'string', 'max:30'],
            'fields'            => ['required', 'array', 'list', 'min:1', 'max:30'],
            'fields.*'          => ['required', 'array:key,name,type,required,min,max'],
            'fields.*.key'      => ['required', 'string', 'max:60', 'regex:/^[A-Za-z_][A-Za-z_0-9]*$/', 'distinct:strict'],
            'fields.*.name'     => ['required', 'string', 'max:120'],
            'fields.*.type'     => ['required', 'in:Character,Integer,Float,Datetime'],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.min'      => ['nullable', 'numeric'],
            'fields.*.max'      => ['nullable', 'numeric'],
            'formula'           => ['nullable', 'string', 'max:1000'],
            'outputField'       => ['nullable', 'string', 'max:60'],
        ])->validate();
        $titles = [];
        foreach ($definition['fields'] as $field) {
            $title = mb_strtolower(trim($field['name']));
            if (isset($titles[$title])) {
                throw new InvalidArgumentException('Input titles must be unique.');
            }
            $titles[$title] = true;
            foreach (['min', 'max'] as $bound) {
                if (isset($field[$bound])) {
                    if (! $this->numeric($field['type'])) {
                        throw new InvalidArgumentException('Limits apply only to numeric fields.');
                    }
                    $this->decimal($field[$bound]);
                }
            }
            if (isset($field['min'], $field['max']) && BigDecimal::of((string) $field['min'])->isGreaterThan((string) $field['max'])) {
                throw new InvalidArgumentException('Minimum cannot exceed maximum.');
            }
        }
        if (filled($definition['formula'] ?? null)) {
            $this->formula->parse($definition['formula'], $this->numericFields($definition));
        } elseif (! collect($definition['fields'])->contains('key', $definition['outputField'] ?? null)) {
            throw new InvalidArgumentException('Choose a valid tracker output field.');
        }
        $definition['unit'] ??= '';
        $definition['formula'] ??= '';

        return $definition;
    }

    public function outputType(array $definition): string
    {
        return filled($definition['formula'] ?? null)
            ? 'Float'
            : collect($definition['fields'])->firstWhere('key', $definition['outputField'])['type'];
    }

    public function numeric(string $type): bool
    {
        return in_array($type, ['Integer', 'Float'], true);
    }

    public function calculate(array $definition, array $raw): array
    {
        $definition = $this->validate($definition);
        $fields = collect($definition['fields'])->keyBy('key');
        if (array_diff(array_keys($raw), $fields->keys()->all())) {
            throw new InvalidArgumentException('Entry contains an unknown input key.');
        }
        $values = [];
        foreach ($fields as $key => $field) {
            $value = $raw[$key] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                if ($field['required']) {
                    throw new InvalidArgumentException($field['name'].' is required.');
                }

                continue;
            }
            if (! is_scalar($value) || is_bool($value)) {
                throw new InvalidArgumentException($field['name'].' must be a typed scalar value.');
            }
            $original = $value;
            $value = trim((string) $value);
            if ($this->numeric($field['type'])) {
                $number = $this->decimal($original);
                if ($field['type'] === 'Integer' && ! $number->getFractionalPart()->isZero()) {
                    throw new InvalidArgumentException($field['name'].' must be a whole number.');
                }
                if (isset($field['min']) && $number->isLessThan((string) $field['min'])) {
                    throw new InvalidArgumentException($field['name'].' is below its minimum.');
                }
                if (isset($field['max']) && $number->isGreaterThan((string) $field['max'])) {
                    throw new InvalidArgumentException($field['name'].' exceeds its maximum.');
                }
                $values[$key] = (string) $number;
            } elseif ($field['type'] === 'Datetime') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
                if (! $date || $date->format('Y-m-d\TH:i') !== $value) {
                    throw new InvalidArgumentException($field['name'].' must be a valid local date and time.');
                }
                $values[$key] = $value;
            } else {
                if (mb_strlen($value) > 2000) {
                    throw new InvalidArgumentException($field['name'].' exceeds 2000 characters.');
                }
                $values[$key] = $value;
            }
        }
        $type = $this->outputType($definition);
        if ($this->numeric($type)) {
            $tree = $this->formula->parse($definition['formula'] ?: $definition['outputField'], $this->numericFields($definition));
            $result = $this->formula->calculate($tree, $values);
        } else {
            $result = $values[$definition['outputField']] ?? null;
            if ($result === null) {
                throw new InvalidArgumentException('Tracker output is missing.');
            }
        }

        return ['inputs' => $values, 'result_type' => $type, 'result_numeric' => $this->numeric($type) ? $result : null, 'result_text' => $this->numeric($type) ? null : $result];
    }

    private function decimal(mixed $value): BigDecimal
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Numeric inputs must be finite.');
            }
            $value = BigDecimal::of((string) $value)->strippedOfTrailingZeros()->toString();
        }
        if (! preg_match('/^[+-]?(?:\d{1,18}(?:\.\d{1,6})?|\.\d{1,6})$/', (string) $value)) {
            throw new InvalidArgumentException('Use a decimal with at most 18 integer and 6 fractional digits.');
        }

        return BigDecimal::of((string) $value);
    }

    private function numericFields(array $definition): array
    {
        return collect($definition['fields'])->filter(fn (array $field): bool => $this->numeric($field['type']))->pluck('name', 'key')->all();
    }
}
