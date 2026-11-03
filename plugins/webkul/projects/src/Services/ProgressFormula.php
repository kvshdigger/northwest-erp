<?php

namespace Webkul\Project\Services;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class ProgressFormula
{
    /** @param array<string, string> $numericFields */
    public function parse(string $expression, array $numericFields): array
    {
        if (strlen($expression) > 1000) {
            throw new InvalidArgumentException('Formula exceeds 1000 characters.');
        }

        preg_match_all('/[A-Za-z_][A-Za-z_0-9]*|(?:\d+(?:\.\d+)?|\.\d+)|[()+*\/-]|\S/', $expression, $matches);
        $tokens = $matches[0];
        if (count($tokens) === 0 || count($tokens) > 200) {
            throw new InvalidArgumentException('Use between 1 and 200 formula tokens.');
        }

        $position = 0;
        $node = $this->expression($tokens, $position, $numericFields);
        if ($position !== count($tokens)) {
            throw new InvalidArgumentException('Unexpected formula token: '.$tokens[$position]);
        }

        return $node;
    }

    public function calculate(array $node, array $values): string
    {
        $result = $this->evaluate($node, $values);
        if ($result->isLessThanOrEqualTo(0) || $result->isGreaterThanOrEqualTo('1000000000000000000')) {
            throw new InvalidArgumentException('Recorded quantity must be positive and less than 10^18.');
        }
        $rounded = $result->toScale(6, RoundingMode::HalfUp);
        if ($rounded->isZero() || $rounded->isGreaterThanOrEqualTo('1000000000000000000')) {
            throw new InvalidArgumentException('Recorded quantity is outside the supported six-decimal range.');
        }

        return (string) $rounded;
    }

    private function expression(array $tokens, int &$position, array $fields): array
    {
        $node = $this->term($tokens, $position, $fields);
        while (in_array($tokens[$position] ?? null, ['+', '-'], true)) {
            $node = ['operator' => $tokens[$position++], 'left' => $node, 'right' => $this->term($tokens, $position, $fields)];
        }

        return $node;
    }

    private function term(array $tokens, int &$position, array $fields): array
    {
        $node = $this->primary($tokens, $position, $fields);
        while (in_array($tokens[$position] ?? null, ['*', '/'], true)) {
            $node = ['operator' => $tokens[$position++], 'left' => $node, 'right' => $this->primary($tokens, $position, $fields)];
        }

        return $node;
    }

    private function primary(array $tokens, int &$position, array $fields): array
    {
        $token = $tokens[$position++] ?? '';
        if ($token === '+' || $token === '-') {
            return ['operator' => $token === '+' ? 'positive' : 'negative', 'right' => $this->primary($tokens, $position, $fields)];
        }
        if ($token === '(') {
            $node = $this->expression($tokens, $position, $fields);
            if (($tokens[$position++] ?? '') !== ')') {
                throw new InvalidArgumentException('Missing closing parenthesis.');
            }

            return $node;
        }
        if (preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/', $token)) {
            if (strlen($token) > 30) {
                throw new InvalidArgumentException('Formula constants may contain at most 30 characters.');
            }

            return ['literal' => $token];
        }
        if (array_key_exists($token, $fields)) {
            return ['key' => $token];
        }

        throw new InvalidArgumentException('Unknown numeric input or unexpected token: '.($token ?: 'end'));
    }

    private function evaluate(array $node, array $values): BigRational
    {
        if (isset($node['literal'])) {
            return BigRational::of($node['literal']);
        }
        if (isset($node['key'])) {
            if (! isset($values[$node['key']])) {
                throw new InvalidArgumentException('Missing numeric value: '.$node['key']);
            }

            return BigRational::of($values[$node['key']]);
        }
        $right = $this->evaluate($node['right'], $values);
        if ($node['operator'] === 'positive') {
            return $right;
        }
        if ($node['operator'] === 'negative') {
            return $right->negated();
        }
        $left = $this->evaluate($node['left'], $values);
        if ($node['operator'] === '/' && $right->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return match ($node['operator']) {
            '+' => $left->plus($right),
            '-' => $left->minus($right),
            '*' => $left->multipliedBy($right),
            '/' => $left->dividedBy($right),
        };
    }
}
