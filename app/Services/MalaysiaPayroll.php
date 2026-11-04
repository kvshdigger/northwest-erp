<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MalaysiaPayroll
{
    public function epf(int $wages, string $citizenship, int $age): array
    {
        if ($wages <= 0) {
            return [0, 0];
        }
        if ($citizenship === 'foreign') {
            return [$this->upRinggit($wages * 2, 100), $this->upRinggit($wages * 2, 100)];
        }
        $this->check($wages <= 2000000, 'Wages over RM20,000 require specialist verification of EPF rounding; automatic issue is blocked.');
        if ($wages <= 1000) {
            return [0, 0];
        }
        $step = $wages <= 500000 ? 2000 : 10000;
        $band = intdiv($wages + $step - 1, $step) * $step;
        $employeeRate = $age < 60 ? 1100 : ($citizenship === 'pr' ? 550 : 0);
        $employerRate = $age < 60 ? ($wages <= 500000 ? 1300 : 1200) : ($citizenship === 'pr' ? ($wages <= 500000 ? 650 : 600) : 400);

        return [$this->upRinggit($band * $employeeRate, 10000), $this->upRinggit($band * $employerRate, 10000)];
    }

    private function upRinggit(int $numerator, int $divisor): int
    {
        return intdiv($numerator + $divisor * 100 - 1, $divisor * 100) * 100;
    }

    public function socso(int $wages, string $category, bool $skbbk): array
    {
        if ($wages <= 0) {
            return [0, 0, 0];
        }
        $row = collect(config('malaysia-payroll.socso'))->first(fn ($row) => $wages <= $row['upper_minor']) ?? collect(config('malaysia-payroll.socso'))->last();

        return [$category === 'first' ? $row['employee_first'] : 0, $category === 'first' ? $row['employer_first'] : $row['employer_second'], $skbbk ? $row['skbbk'] : 0];
    }

    public function eis(int $wages, bool $eligible): array
    {
        if (! $eligible || $wages <= 0) {
            return [0, 0];
        }
        $table = config('malaysia-payroll.eis');
        $this->check((bool) $table, 'The verified EIS schedule is missing.');
        $row = collect($table)->first(fn ($row) => $wages <= $row['upper_minor']) ?? collect($table)->last();

        return [$row['amount_minor'], $row['amount_minor']];
    }

    public function pcb(int $taxable, int $epf, array $profile, array $ytd, int $month): array
    {
        if ($profile['tax_residency'] === 'nonresident') {
            $amount = intdiv($taxable * 30, 100);

            return ['amount' => $this->fiveSen($amount), 'chargeable' => $taxable, 'qualifying_epf' => 0];
        }
        $this->check($profile['tax_scheme'] === 'standard', 'Special tax incentive schemes require their approved specialist formula.');
        $n = 12 - $month;
        $k = min($ytd['epf'], 400000);
        $k1 = min($epf, max(0, 400000 - $k));
        $k2 = $n ? min($k1, intdiv(max(0, 400000 - $k - $k1), $n)) : 0;
        $relief = 900000 + ($profile['tax_category'] === '2' ? 400000 : 0)
            + ($profile['disabled_person'] ? 700000 : 0) + ($profile['disabled_spouse'] ? 600000 : 0)
            + app(HrWorkflows::class)->minor($profile['child_relief'], 2) + app(HrWorkflows::class)->minor($profile['annual_tp1_relief'], 2);
        $chargeable = max(0, $ytd['taxable'] - $k + $taxable - $k1 + ($taxable - $k2) * $n - $relief);
        $tax = $this->annualTax($chargeable, $profile['tax_category']);
        $beforeZakat = max(0, intdiv($tax - $ytd['pcb'] - $ytd['zakat'], $n + 1));
        $zakat = app(HrWorkflows::class)->minor($profile['monthly_zakat'], 2);
        $amount = $beforeZakat < 1000 ? 0 : $this->fiveSen(max(0, $beforeZakat - $zakat));

        return ['amount' => $amount, 'chargeable' => $chargeable, 'qualifying_epf' => $k1, 'future_month_epf_relief' => $k2, 'annual_tax' => $tax, 'before_current_zakat' => $beforeZakat];
    }

    public function annualTax(int $chargeable, string $category): int
    {
        $brackets = [[500000, 0, 0, 0], [2000000, 500000, 1, -40000], [3500000, 2000000, 3, -25000], [5000000, 3500000, 6, 60000], [7000000, 5000000, 11, 150000], [10000000, 7000000, 19, 370000], [40000000, 10000000, 25, 940000], [60000000, 40000000, 26, 8440000], [200000000, 60000000, 28, 13640000], [PHP_INT_MAX, 200000000, 30, 52840000]];
        foreach ($brackets as [$upper, $base, $rate, $tax]) {
            if ($chargeable <= $upper) {
                return max(0, intdiv(($chargeable - $base) * $rate, 100) + $tax - ($category === '2' && $chargeable <= 3500000 && $chargeable > 500000 ? 40000 : 0));
            }
        }

        return 0;
    }

    private function fiveSen(int $amount): int
    {
        return intdiv($amount + 4, 5) * 5;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['payroll' => $message]);
        }
    }
}
