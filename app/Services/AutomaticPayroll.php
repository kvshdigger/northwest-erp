<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\HrRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

class AutomaticPayroll
{
    public function profileFields(): array
    {
        $f = fn ($key, $label, $type = 'text', $required = true) => compact('key', 'label', 'type', 'required');
        $choice = fn ($key, $label, $choices) => [...$f($key, $label, 'choice'), 'choices' => $choices];

        return [
            $f('title', 'Compensation version name'), $f('effective_from', 'Effective from', 'date'), $f('effective_to', 'Effective to', 'date'),
            $f('employee_number', 'Employee number', 'text', false), $f('epf_number', 'EPF account number', 'text', false), $f('socso_number', 'SOCSO account number', 'text', false), $f('tax_number', 'Income tax number', 'text', false),
            $f('monthly_base', 'Monthly basic salary (MYR)', 'number'), $f('allowances', 'Recurring allowances', 'allowances', false),
            $f('employment_start', 'Employment start', 'date'), $f('employment_end', 'Last employment day', 'date', false), $f('date_of_birth', 'Date of birth', 'date'),
            $choice('citizenship', 'Citizenship category', ['malaysian' => 'Malaysian citizen', 'pr' => 'Permanent resident', 'foreign' => 'Other non-citizen']),
            $choice('epf_category', 'EPF registration category', ['standard' => 'Standard current category', 'legacy' => 'Foreign member elected before August 1998 / special category']),
            $choice('socso_category', 'Verified SOCSO category', ['first' => 'First category (injury and invalidity)', 'second' => 'Second category (injury only)']),
            $choice('eis_prior_contribution', 'EIS contributions before age 57', ['yes' => 'Yes / already contributing', 'no' => 'No prior contributions']),
            $choice('skbbk_status', 'Verified LINDUNG 24 Jam participation', ['enrolled' => 'Enrolled / compulsory category', 'opted_out' => 'Validly opted out / not enrolled']),
            $f('working_days', 'Working weekdays (1=Mon … 7=Sun)'),
            $choice('tax_residency', 'Tax residency', ['resident' => 'Resident', 'nonresident' => 'Non-resident / not known resident']),
            $choice('tax_scheme', 'Tax regime', ['standard' => 'Standard PCB', 'special' => 'REP / approved special tax incentive']),
            $choice('tax_category', 'PCB family category', ['1' => 'Single, no qualifying children', '2' => 'Married, spouse not working', '3' => 'Spouse working / divorced / widowed / qualifying children']),
            $choice('disabled_person', 'Verified disabled individual relief', ['no' => 'No', 'yes' => 'Yes']),
            $choice('disabled_spouse', 'Verified disabled spouse relief', ['no' => 'No', 'yes' => 'Yes']),
            $f('child_relief', 'Verified annual qualifying child relief (MYR)', 'number'),
            $f('annual_tp1_relief', 'Approved accumulated TP1 optional deductions for tax year (MYR)', 'number'),
            $f('monthly_zakat', 'Recurring monthly zakat (MYR)', 'number'),
            $f('opening_month', 'Opening YTD includes months through (0=none, 1=Jan … 11=Nov)', 'number'),
            $f('opening_taxable', 'Verified opening YTD taxable remuneration (MYR)', 'number'),
            $f('opening_epf', 'Verified opening YTD qualifying EPF paid (MYR)', 'number'),
            $f('opening_pcb', 'Verified opening YTD PCB paid, excluding extra requested tax (MYR)', 'number'),
            $f('opening_zakat', 'Verified opening YTD zakat (MYR)', 'number'),
            $f('declaration', 'Verified TP1/TP3, contribution eligibility and allowance classification reference'),
        ];
    }

    public function validateProfile(array $data): array
    {
        $this->check(($data['currency'] ?? null) === 'MYR' && $data['precision'] === 2, 'Automatic Malaysia payroll requires MYR with two decimal places.');
        $this->check(preg_match('/^[1-7](?:,[1-7])*$/', $data['working_days']) && count(array_unique(explode(',', $data['working_days']))) === count(explode(',', $data['working_days'])), 'Choose distinct working weekdays, e.g. 1,2,3,4,5.');
        $this->check($data['effective_from'] >= config('malaysia-payroll.from') && $data['effective_to'] <= config('malaysia-payroll.to'), 'The verified automatic rule pack currently covers tax year 2026 only.');
        foreach (['monthly_base', 'child_relief', 'annual_tp1_relief', 'monthly_zakat', 'opening_taxable', 'opening_epf', 'opening_pcb', 'opening_zakat'] as $key) {
            app(HrWorkflows::class)->minor($data[$key], 2);
        }
        $this->check((int) $data['opening_month'] !== 0 || collect(['opening_taxable', 'opening_epf', 'opening_pcb', 'opening_zakat'])->every(fn ($field) => (float) $data[$field] === 0.0), 'Opening month zero requires zero opening YTD amounts.');
        $this->check($data['tax_category'] !== '1' || ((float) $data['child_relief'] === 0.0 && $data['disabled_spouse'] === 'no'), 'Single category 1 cannot claim spouse or qualifying child relief. Select the correct family category.');
        $gross = app(HrWorkflows::class)->minor($data['monthly_base'], 2);
        $this->check($gross >= config('malaysia-payroll.minimum_monthly_base_minor'), 'This automatic profile supports ordinary full-time monthly employment. Basic salary below the current minimum requires specialist eligibility review.');
        foreach ($data['allowances'] ?? [] as $allowance) {
            $gross += app(HrWorkflows::class)->minor($allowance['amount'], 2);
        }

        return ['monthly_gross_minor' => $gross];
    }

    public function preview(User $user, array $input, ?int $exclude = null): array
    {
        $hr = app(HrWorkflows::class);
        abort_unless($user->is_active && $hr->enabled() && $hr->manager($user, 'payroll'), 403);
        $company = Company::query()->with('currency')->whereIn('id', app(NativeHr::class)->companyIds($user))->findOrFail($input['company_id']);
        $this->check($company->currency?->name === 'MYR' && (int) $company->currency?->decimal_places === 2, 'Configure company currency MYR with two decimal places.');
        $employee = Employee::query()->where('company_id', $company->id)->findOrFail($input['employee_id']);
        $from = CarbonImmutable::parse($input['period_from']);
        $to = CarbonImmutable::parse($input['period_to']);
        $pay = CarbonImmutable::parse($input['pay_date']);
        $this->check($from->format('Y-m') === $to->format('Y-m') && $from <= $to, 'Automatic monthly payroll must be within one calendar wage month.');
        $this->check($from->toDateString() >= config('malaysia-payroll.from') && $pay->toDateString() <= config('malaysia-payroll.to') && $pay >= $to, 'Choose a wage period and pay date covered by the verified 2026 rules.');
        $profiles = HrRecord::query()->where('kind', 'compensation')->where('employee_id', $employee->id)->where('company_id', $company->id)->whereIn('state', ['saved', 'submitted', 'approved'])->orderByDesc('id')->get();
        $profile = $profiles->first(fn ($row) => $row->data['effective_from'] <= $input['period_from'] && $row->data['effective_to'] >= $input['period_to']);
        $this->check((bool) $profile, 'Save this employee’s compensation profile with effective dates covering the entire period.');
        $data = $profile->data;
        $this->check(! $profiles->contains(fn ($row) => $row->id > $profile->id && $row->data['effective_from'] <= $input['period_to'] && $row->data['effective_to'] >= $input['period_from']), 'A newer compensation version changes within this period. A consolidated specialist calculation is required.');
        $activeFrom = max($from->toDateString(), $data['employment_start']);
        $activeTo = min($to->toDateString(), $data['employment_end'] ?? $to->toDateString());
        $this->check($activeFrom <= $activeTo, 'There are no employed days within this period.');
        $monthStart = $from->startOfMonth();
        $monthEnd = $from->endOfMonth();
        $existing = HrRecord::query()->where('employee_id', $employee->id)->where('kind', 'payroll')->whereNotIn('state', ['withdrawn', 'rejected'])->when($exclude, fn ($query) => $query->whereKeyNot($exclude))->where('starts_at', '<=', $monthEnd)->where('ends_at', '>=', $monthStart)->exists();
        $this->check(! $existing, 'This employee already has a payroll record in the wage month. Automatic contributions require one consolidated monthly payroll.');
        $overtime = HrRecord::query()->where('employee_id', $employee->id)->where('kind', 'attendance')->whereNotIn('state', ['withdrawn', 'rejected'])->where('starts_at', '<=', $monthEnd)->where('ends_at', '>=', $monthStart)->get();
        $this->check(! $overtime->contains(fn ($record) => (int) ($record->data['overtime_minutes'] ?? 0) > 0), 'Overtime exists in this wage month. Resolve approval and ordinary/rest-day/public-holiday classification through specialist payroll; automatic salary must not omit it.');
        $leaves = collect();
        if (Schema::hasTable('time_off_leaves')) {
            $leaves = Leave::query()->where('employee_id', $employee->id)->where('company_id', $company->id)->with('holidayStatus')->where('state', '!=', State::REFUSE->value)->whereDate('request_date_from', '<=', $activeTo)->whereDate('request_date_to', '>=', $activeFrom)->orderBy('id')->get();
        }
        $this->check(! $leaves->contains(fn ($leave) => $leave->holidayStatus?->unpaid && $leave->state !== State::VALIDATE_TWO), 'Resolve pending unpaid leave before payroll calculation.');
        $unpaidUnits = 0;
        $workdays = explode(',', $data['working_days']);
        $used = [];
        foreach ($leaves->filter(fn ($leave) => $leave->holidayStatus?->unpaid && $leave->state === State::VALIDATE_TWO) as $leave) {
            $this->check(! $leave->request_unit_hours, 'Hourly unpaid leave needs a specialist eligibility calculation.');
            $start = max($activeFrom, substr((string) $leave->getRawOriginal('request_date_from'), 0, 10));
            $end = min($activeTo, substr((string) $leave->getRawOriginal('request_date_to'), 0, 10));
            for ($day = CarbonImmutable::parse($start); $day->toDateString() <= $end; $day = $day->addDay()) {
                if (in_array((string) $day->isoWeekday(), $workdays, true)) {
                    $this->check(! isset($used[$day->toDateString()]), 'Overlapping unpaid leave requires HR reconciliation.');
                    $used[$day->toDateString()] = true;
                    $unpaidUnits += $leave->request_unit_half ? 1 : 2;
                }
            }
        }
        $employedDays = (int) CarbonImmutable::parse($activeFrom)->diffInDays(CarbonImmutable::parse($activeTo)) + 1;
        $eligibleUnits = $employedDays * 2 - $unpaidUnits;
        $this->check($eligibleUnits >= 0, 'Unpaid leave exceeds eligible employed days.');
        $divisor = $from->daysInMonth * 2;
        $prorate = fn (int $amount): int => intdiv($amount * $eligibleUnits + intdiv($divisor, 2), $divisor);
        $base = $prorate($hr->minor($data['monthly_base'], 2));
        $gross = $epfWages = $socsoWages = $eisWages = $taxable = $base;
        $lines = [];
        foreach ($data['allowances'] ?? [] as $allowance) {
            $amount = $hr->minor($allowance['amount'], 2);
            $amount = $allowance['prorate'] ? $prorate($amount) : $amount;
            $gross += $amount;
            $epfWages += $allowance['epf'] ? $amount : 0;
            $socsoWages += $allowance['socso'] ? $amount : 0;
            $eisWages += $allowance['eis'] ? $amount : 0;
            $taxable += $allowance['taxable'] ? $amount : 0;
            $lines[] = ['label' => $allowance['label'], 'category' => 'earning', 'amount_minor' => $amount];
        }
        $birth = CarbonImmutable::parse($data['date_of_birth']);
        $age = (int) $birth->diffInYears($monthEnd);
        $this->check($age >= 18 && $age < 75, 'This employee’s age requires specialist statutory eligibility review.');
        $this->check(! ($birth->month === $from->month && in_array($age, [18, 55, 57, 60, 75], true)), 'A statutory age-category transition occurs this month; verify the contribution category with the agencies.');
        $this->check($data['epf_category'] === 'standard', 'Legacy/special EPF registration needs specialist calculation.');
        $this->check($data['socso_category'] !== 'first' || $age < 60, 'Employees aged 60 or above require the verified second SOCSO category.');
        $this->check($data['socso_category'] !== 'second' || $age >= 55, 'Second-category eligibility for this age requires specialist review.');
        $this->check($data['citizenship'] !== 'foreign' || $data['skbbk_status'] === 'enrolled' || $from < CarbonImmutable::parse('2026-06-01'), 'Foreign-worker SKBBK participation must be verified under the current compulsory coverage.');
        $this->check($from->format('Y-m') !== '2026-06' || $data['skbbk_status'] === 'enrolled', 'June 2026 LINDUNG 24 Jam coverage predates the opt-out changes and requires specialist reconciliation.');
        $priorEis = HrRecord::query()->where('employee_id', $employee->id)->where('company_id', $company->id)->where('kind', 'payroll')->where('state', 'approved')->get()->contains(fn ($record) => isset($record->data['automatic']) && $record->data['period_to'] < $birth->addYears(57)->toDateString() && collect($record->data['lines'])->contains(fn ($line) => $line['label'] === 'EIS employee' && $line['amount_minor'] > 0));
        $eisEligible = $data['citizenship'] !== 'foreign' && $age < 60 && ($age < 57 || $data['eis_prior_contribution'] === 'yes' || $priorEis);
        $stat = app(MalaysiaPayroll::class);
        [$epfEmployee, $epfEmployer] = $stat->epf($epfWages, $data['citizenship'], $age);
        [$socsoEmployee, $socsoEmployer, $skbbk] = $stat->socso($socsoWages, $data['socso_category'], $data['skbbk_status'] === 'enrolled' && $from >= CarbonImmutable::parse('2026-06-01'));
        [$eisEmployee, $eisEmployer] = $stat->eis($eisWages, $eisEligible);
        $ytd = $this->ytd($employee, $data, $pay, $exclude);
        $taxProfile = [...$data, 'disabled_person' => $data['disabled_person'] === 'yes', 'disabled_spouse' => $data['disabled_spouse'] === 'yes'];
        $pcb = $stat->pcb($taxable, $epfEmployee, $taxProfile, $ytd, $pay->month);
        $zakat = $data['tax_residency'] === 'resident' ? $hr->minor($data['monthly_zakat'], 2) : 0;
        foreach ([['EPF employee', 'deduction', $epfEmployee], ['SOCSO employee', 'deduction', $socsoEmployee], ['EIS employee', 'deduction', $eisEmployee], ['LINDUNG 24 Jam / SKBBK', 'deduction', $skbbk], ['PCB / MTD', 'deduction', $pcb['amount']], ['Zakat', 'deduction', $zakat], ['EPF employer', 'employer', $epfEmployer], ['SOCSO employer', 'employer', $socsoEmployer], ['EIS employer', 'employer', $eisEmployer]] as [$label, $category, $amount]) {
            $lines[] = ['label' => $label, 'category' => $category, 'amount_minor' => $amount];
        }
        $deductions = collect($lines)->where('category', 'deduction')->sum('amount_minor');
        $this->check($deductions <= $gross, 'Calculated deductions exceed gross pay.');
        $result = [
            'period_from' => $input['period_from'], 'period_to' => $input['period_to'], 'pay_date' => $input['pay_date'], 'currency' => 'MYR', 'precision' => 2,
            'company_id'  => $company->id, 'employee_id' => $employee->id, 'company_name' => $company->name, 'employee_name' => $employee->name,
            'base_minor'  => $base, 'gross_minor' => $gross, 'deductions_minor' => $deductions, 'employer_minor' => collect($lines)->where('category', 'employer')->sum('amount_minor'), 'net_minor' => $gross - $deductions, 'lines' => $lines,
            'automatic'   => ['profile_id' => $profile->id, 'profile_revision' => $profile->revision, 'profile_snapshot' => $data, 'rule_version' => config('malaysia-payroll.version'), 'rules_hash' => hash('sha256', json_encode(config('malaysia-payroll'))), 'wage_month' => $from->format('Y-m'), 'tax_month' => $pay->format('Y-m'), 'calendar_days' => $from->daysInMonth, 'employed_days' => $employedDays, 'unpaid_days' => $unpaidUnits / 2, 'eligible_days' => $eligibleUnits / 2, 'epf_wages_minor' => $epfWages, 'socso_wages_minor' => $socsoWages, 'eis_wages_minor' => $eisWages, 'taxable_minor' => $taxable, 'epf_employee_minor' => $epfEmployee, 'pcb_minor' => $pcb['amount'], 'zakat_minor' => $zakat, 'ytd' => $ytd, 'pcb_trace' => $pcb, 'leave_revisions' => $leaves->map(fn ($leave) => $leave->getAttributes())->all()],
        ];
        $result['payslip_snapshot'] = app(PayslipTemplate::class)->employeeSnapshot($employee, $data);
        $result['calculation_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function ytd(Employee $employee, array $profile, CarbonImmutable $pay, ?int $exclude): array
    {
        $hr = app(HrWorkflows::class);
        $opening = (int) $profile['opening_month'];
        $this->check($opening < $pay->month, 'Opening YTD month must be before the pay month.');
        $result = ['taxable' => $hr->minor($profile['opening_taxable'], 2), 'epf' => $hr->minor($profile['opening_epf'], 2), 'pcb' => $hr->minor($profile['opening_pcb'], 2), 'zakat' => $hr->minor($profile['opening_zakat'], 2)];
        $rows = HrRecord::query()->where('employee_id', $employee->id)->where('kind', 'payroll')->whereNotIn('state', ['withdrawn', 'rejected'])->when($exclude, fn ($query) => $query->whereKeyNot($exclude))->orderBy('id')->get();
        $seen = [];
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->data['pay_date']);
            if ($date->year !== $pay->year || $date->month <= $opening) {
                continue;
            }
            $this->check($date->month < $pay->month, 'Process and approve payroll chronologically, with one consolidated payment per tax month.');
            $this->check($row->state === 'approved' && isset($row->data['automatic']), 'Reconcile earlier manual or unapproved payroll into the verified opening YTD before continuing.');
            $this->check(! isset($seen[$date->month]), 'Multiple payroll records in an earlier tax month require reconciliation.');
            $seen[$date->month] = true;
            $auto = $row->data['automatic'];
            foreach (['taxable' => 'taxable_minor', 'epf' => 'epf_employee_minor', 'pcb' => 'pcb_minor', 'zakat' => 'zakat_minor'] as $key => $source) {
                $result[$key] += $auto[$source];
            }
        }
        for ($month = $opening + 1; $month < $pay->month; $month++) {
            $this->check(isset($seen[$month]), 'YTD history has a gap. Import verified opening totals through the last processed month.');
        }

        return $result;
    }

    public function save(User $user, array $input): HrRecord
    {
        return (new HrRecord)->getConnection()->transaction(function () use ($user, $input): HrRecord {
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && app(HrWorkflows::class)->manager($user, 'payroll'), 403);
            $hashInput = $input;
            unset($hashInput['request_id']);
            ksort($hashInput);
            $hash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR));
            if ($receipt = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $input['request_id'])->first()) {
                abort_unless($receipt->kind === 'hr-payroll-auto' && hash_equals($receipt->payload_hash, $hash), 409, 'Submission key already used.');

                return app(HrWorkflows::class)->record($user, 'payroll', $receipt->record_id);
            }
            Company::query()->whereIn('id', app(NativeHr::class)->companyIds($user))->whereKey($input['company_id'])->lockForUpdate()->firstOrFail();
            Employee::query()->where('company_id', $input['company_id'])->whereKey($input['employee_id'])->lockForUpdate()->firstOrFail();
            $data = $this->preview($user, $input);
            abort_unless(hash_equals($data['calculation_hash'], $input['calculation_hash']), 409, 'Compensation, leave or payroll history changed. Calculate the preview again.');
            $record = HrRecord::query()->create(['company_id' => $input['company_id'], 'employee_id' => $input['employee_id'], 'kind' => 'payroll', 'state' => 'draft', 'creator_id' => $user->id, 'starts_at' => CarbonImmutable::parse($input['period_from'])->startOfDay(), 'ends_at' => CarbonImmutable::parse($input['period_to'])->endOfDay(), 'data' => $data]);
            BusinessEvent::query()->create(['company_id' => $record->company_id, 'actor_id' => $user->id, 'kind' => 'hr-payroll-auto', 'record_id' => $record->id, 'action' => 'created', 'request_id' => $input['request_id'], 'payload_hash' => $hash, 'changes' => $data]);

            return $record;
        });
    }

    private function check(bool|int $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['payroll' => $message]);
        }
    }
}
