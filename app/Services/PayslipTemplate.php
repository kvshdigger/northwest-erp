<?php

namespace App\Services;

use App\Models\HrRecord;
use Carbon\CarbonImmutable;
use Webkul\Employee\Models\Employee;

class PayslipTemplate
{
    public function employeeSnapshot(Employee $employee, array $profile = []): array
    {
        $identity = $employee->identification_id ?: $employee->passport_id;

        return [
            'employee_number' => $profile['employee_number'] ?? null,
            'department'      => $employee->department?->name,
            'position'        => $employee->job_title,
            'identity'        => $identity ? str_repeat('•', max(0, mb_strlen($identity) - 4)).mb_substr($identity, -4) : null,
            'epf_number'      => $profile['epf_number'] ?? null,
            'socso_number'    => $profile['socso_number'] ?? null,
            'tax_number'      => $profile['tax_number'] ?? null,
        ];
    }

    public function data(HrRecord $record): array
    {
        $data = $record->data;
        $precision = $data['precision'];
        $money = fn ($value) => number_format($value / (10 ** $precision), $precision, '.', ',');
        $lines = collect($data['lines']);
        $format = fn ($row) => ['label' => $row['label'], 'amount' => $money($row['amount_minor'])];
        $snapshot = $data['payslip_snapshot'] ?? [];
        $auto = $data['automatic'] ?? [];

        return [
            'sample'       => false, 'reference' => 'PAY-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
            'company_name' => $data['company_name'], 'employee_name' => $data['employee_name'],
            'period_title' => strtoupper(CarbonImmutable::parse($data['period_to'])->format('F Y')),
            'period_from'  => $data['period_from'], 'period_to' => $data['period_to'], 'pay_date' => $data['pay_date'], 'currency' => $data['currency'],
            ...array_map(fn ($value) => filled($value) ? $value : '—', array_replace(array_fill_keys(['employee_number', 'identity', 'department', 'position', 'epf_number', 'socso_number', 'tax_number'], null), $snapshot)),
            'monthly_base'  => isset($auto['profile_snapshot']['monthly_base']) ? $money(app(HrWorkflows::class)->minor($auto['profile_snapshot']['monthly_base'], $precision)) : '—',
            'eligible_days' => isset($auto['eligible_days']) ? number_format($auto['eligible_days'], 2, '.', '') : '—',
            'calendar_days' => $auto['calendar_days'] ?? '—', 'unpaid_days' => $auto['unpaid_days'] ?? '—',
            'earnings'      => [['label' => 'Basic pay', 'amount' => $money($data['base_minor'])], ...$lines->where('category', 'earning')->map($format)->values()->all()],
            'deductions'    => $lines->where('category', 'deduction')->map($format)->values()->all(),
            'employer'      => $lines->where('category', 'employer')->map($format)->values()->all(),
            'gross'         => $money($data['gross_minor']), 'total_deductions' => $money($data['deductions_minor']), 'net' => $money($data['net_minor']),
            'annual_leave'  => '—', 'medical_leave' => '—', 'approved_by' => $data['approval_snapshot']['name'] ?? '—',
        ];
    }

    public function sample(): array
    {
        return ['sample' => true, 'reference' => 'TEMPLATE-SAMPLE', 'company_name' => 'Demo Company Sdn Bhd', 'employee_number' => 'JEFF001', 'employee_name' => 'JEFFREY TOH (SAMPLE)', 'identity' => '••••••••1234', 'department' => 'PRO', 'position' => 'Sales Executive', 'epf_number' => 'SAMPLE-EPF', 'socso_number' => 'SAMPLE-SOCSO', 'tax_number' => 'SAMPLE-TAX', 'period_title' => 'JANUARY 2019', 'period_from' => '2019-01-01', 'period_to' => '2019-01-31', 'pay_date' => '2019-01-31', 'currency' => 'MYR', 'monthly_base' => '3,000.00', 'eligible_days' => '31.00', 'calendar_days' => 31, 'unpaid_days' => '0.00', 'earnings' => [['label' => 'Basic pay', 'amount' => '3,000.00'], ['label' => 'Daily attendance', 'amount' => '23.07'], ['label' => 'Meal', 'amount' => '93.00'], ['label' => 'Night shift allowance', 'amount' => '10.00'], ['label' => 'Normal OT', 'amount' => '86.54'], ['label' => 'Rest OT', 'amount' => '734.44']], 'deductions' => [['label' => 'Employee EPF', 'amount' => '319.00'], ['label' => 'Employee SOCSO', 'amount' => '19.25'], ['label' => 'Employee EIS', 'amount' => '7.70'], ['label' => 'Employee PCB', 'amount' => '12.40'], ['label' => 'Early out', 'amount' => '86.54'], ['label' => 'Lateness', 'amount' => '14.40'], ['label' => 'Time off', 'amount' => '1.15']], 'employer' => [['label' => 'EPF (employer)', 'amount' => '377.00'], ['label' => 'SOCSO (employer)', 'amount' => '67.35'], ['label' => 'EIS (employer)', 'amount' => '7.70']], 'gross' => '3,947.05', 'total_deductions' => '460.44', 'net' => '3,486.61', 'annual_leave' => '2.50 DAYS [ 0.00 ]', 'medical_leave' => '0.00 DAYS [ 14.00 ]', 'approved_by' => '____________________________'];
    }
}
