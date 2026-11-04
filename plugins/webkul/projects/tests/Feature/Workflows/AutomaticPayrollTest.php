<?php

use App\Models\HrRecord;
use App\Services\MalaysiaPayroll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Employee;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    TestBootstrapHelper::ensurePluginInstalled('employees');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    config(['project-progress.enabled' => true]);
    $this->permissions = ['v2_hr_manage_payroll', 'v2_hr_approve_payroll'];
    $this->user = SecurityHelper::authenticateWithPermissions($this->permissions)->refresh();
    $this->employee = Employee::factory()->create(['company_id' => $this->user->default_company_id, 'is_active' => true]);
    $this->user->defaultCompany->currency->update(['name' => 'MYR', 'decimal_places' => 2]);
    $this->base = '/admin/v2/project-progress/api/hr-workflows/';
    $this->profile = [
        'company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'title' => 'Verified monthly compensation', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'monthly_base' => '3100.00', 'employment_start' => '2026-10-19', 'employment_end' => null, 'date_of_birth' => '1990-03-02', 'citizenship' => 'malaysian', 'epf_category' => 'standard', 'socso_category' => 'first', 'eis_prior_contribution' => 'yes', 'skbbk_status' => 'opted_out', 'working_days' => '1,2,3,4,5', 'tax_residency' => 'resident', 'tax_scheme' => 'standard', 'tax_category' => '1', 'disabled_person' => 'no', 'disabled_spouse' => 'no', 'child_relief' => '0.00', 'annual_tp1_relief' => '0.00', 'monthly_zakat' => '0.00', 'opening_month' => 9, 'opening_taxable' => '0.00', 'opening_epf' => '0.00', 'opening_pcb' => '0.00', 'opening_zakat' => '0.00', 'declaration' => 'Synthetic verified no prior income / valid opt-out / ordinary wages', 'allowances' => [['label' => 'Housing', 'amount' => '310.00', 'prorate' => true, 'epf' => true, 'socso' => true, 'eis' => true, 'taxable' => true, 'classification' => 'Ordinary cash housing allowance']],
    ];
    $this->input = ['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'period_from' => '2026-10-19', 'period_to' => '2026-10-31', 'pay_date' => '2026-10-31'];
});

function saveCompensation($test, array $overrides = []): int
{
    $record = $test->postJson($test->base.'compensation', ['request_id' => (string) Str::uuid(), 'values' => [...$test->profile, ...$overrides]])->assertCreated()->json();
    expect($record['state'])->toBe('saved')->and($record['actions'])->toBe([]);

    return $record['id'];
}

it('loads salary and allowance from the latest saved profile and prorates October calendar days', function (): void {
    saveCompensation($this);
    $result = $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->assertJsonPath('automatic.calendar_days', 31)->assertJsonPath('automatic.eligible_days', 13)->assertJsonPath('base_minor', 130000)->assertJsonPath('gross_minor', 143000)->json();
    expect($result['lines'][0]['amount_minor'])->toBe(13000)->and($result['automatic']['epf_employee_minor'])->toBe(15900);
});

it('uses actual calendar denominators for January, February and April', function (): void {
    saveCompensation($this, ['employment_start' => '2026-01-01', 'opening_month' => 0]);
    $input = [...$this->input, 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'pay_date' => '2026-01-31'];
    $this->postJson($this->base.'automatic/preview', $input)->assertOk()->assertJsonPath('base_minor', 310000)->assertJsonPath('automatic.eligible_days', 31);
    expect(CarbonImmutable::parse('2028-02-01')->daysInMonth)->toBe(29);
});

it('subtracts only approved unpaid working days and keeps paid leave eligible', function (): void {
    saveCompensation($this);
    $type = LeaveType::create(['name' => 'Unpaid auto test', 'company_id' => $this->user->default_company_id, 'is_active' => true, 'unpaid' => true, 'requires_allocation' => 'no']);
    Leave::create(['employee_id' => $this->employee->id, 'company_id' => $this->user->default_company_id, 'holiday_status_id' => $type->id, 'state' => 'validate_two', 'request_date_from' => '2026-10-20', 'request_date_to' => '2026-10-20', 'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'number_of_days' => 1, 'request_unit_half' => false]);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->assertJsonPath('automatic.unpaid_days', 1)->assertJsonPath('base_minor', 120000)->assertJsonPath('gross_minor', 132000);
});

it('matches official EPF band boundaries and contribution categories', function (): void {
    $calc = app(MalaysiaPayroll::class);
    expect($calc->epf(1000, 'malaysian', 35))->toBe([0, 0])
        ->and($calc->epf(1001, 'malaysian', 35))->toBe([300, 300])
        ->and($calc->epf(300000, 'malaysian', 35))->toBe([33000, 39000])
        ->and($calc->epf(500000, 'malaysian', 35))->toBe([55000, 65000])
        ->and($calc->epf(500001, 'malaysian', 35))->toBe([56100, 61200])
        ->and($calc->epf(300000, 'malaysian', 61))->toBe([0, 12000])
        ->and($calc->epf(300000, 'pr', 61))->toBe([16500, 19500])
        ->and($calc->epf(325000, 'foreign', 35))->toBe([6500, 6500]);
});

it('uses official SOCSO and EIS wage tables with caps and independent employer shares', function (): void {
    $calc = app(MalaysiaPayroll::class);
    expect($calc->socso(3000, 'first', false))->toBe([10, 40, 0])
        ->and($calc->socso(600000, 'first', true))->toBe([2975, 10415, 4465])
        ->and($calc->socso(900000, 'second', false))->toBe([0, 7440, 0])
        ->and($calc->eis(3000, true))->toBe([5, 5])
        ->and($calc->eis(600000, true))->toBe([1190, 1190])
        ->and($calc->eis(900000, true))->toBe([1190, 1190])
        ->and($calc->eis(500000, false))->toBe([0, 0]);
});

it('matches HASiL worked normal-remuneration examples including accumulated EPF and TP1', function (): void {
    $calc = app(MalaysiaPayroll::class);
    $profile = [...$this->profile, 'tax_category' => '3', 'child_relief' => '6000.00', 'disabled_person' => false, 'disabled_spouse' => false];
    $feb = $calc->pcb(550000, 60500, $profile, ['taxable' => 550000, 'epf' => 60500, 'pcb' => 11000, 'zakat' => 0], 2);
    expect($feb['amount'])->toBe(11000)->and($feb['chargeable'])->toBe(4700000);
    $march = $calc->pcb(550000, 60500, [...$profile, 'annual_tp1_relief' => '300.00'], ['taxable' => 1100000, 'epf' => 121000, 'pcb' => 22000, 'zakat' => 0], 3);
    expect($march['amount'])->toBe(10820)->and($march['chargeable'])->toBe(4670007);
});

it('calculates non-resident tax and rounds resident MTD upward to five sen', function (): void {
    $calc = app(MalaysiaPayroll::class);
    $profile = [...$this->profile, 'tax_residency' => 'nonresident'];
    expect($calc->pcb(300000, 0, $profile, ['taxable' => 0, 'epf' => 0, 'pcb' => 0, 'zakat' => 0], 10)['amount'])->toBe(90000);
});

it('saves calculated snapshots once and detects stale previews', function (): void {
    saveCompensation($this);
    $preview = $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->json();
    $payload = [...$this->input, 'calculation_hash' => $preview['calculation_hash'], 'request_id' => (string) Str::uuid()];
    $id = $this->postJson($this->base.'automatic', $payload)->assertCreated()->assertJsonPath('values.base_minor', 130000)->json('id');
    $this->postJson($this->base.'automatic', $payload)->assertCreated()->assertJsonPath('id', $id);
    expect(HrRecord::where('kind', 'payroll')->count())->toBe(1);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertUnprocessable();
});

it('requires saved setup, complete YTD and supported effective dates', function (): void {
    $this->postJson($this->base.'automatic/preview', $this->input)->assertUnprocessable();
    saveCompensation($this, ['opening_month' => 0]);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertUnprocessable();
    $this->postJson($this->base.'automatic/preview', [...$this->input, 'pay_date' => '2027-01-01'])->assertUnprocessable();
});

it('does not allow clients to inject salary, deductions or another company', function (): void {
    saveCompensation($this);
    $this->postJson($this->base.'automatic/preview', [...$this->input, 'base_pay' => 1])->assertUnprocessable();
    $this->postJson($this->base.'automatic/preview', [...$this->input, 'company_id' => 999999])->assertNotFound();
    SecurityHelper::authenticateWithPermissions([]);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertForbidden();
});

it('refuses stale calculation hashes and does not issue stale automatic drafts', function (): void {
    saveCompensation($this);
    $old = $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->json();
    saveCompensation($this, ['monthly_base' => '3200.00']);
    $this->postJson($this->base.'automatic', [...$this->input, 'request_id' => (string) Str::uuid(), 'calculation_hash' => $old['calculation_hash']])->assertConflict();
    expect(HrRecord::where('kind', 'payroll')->count())->toBe(0);
});

it('recalculates before submission and approval and freezes issued payslip details', function (): void {
    $this->employee->update(['job_title' => 'Original role', 'identification_id' => '900302101234']);
    saveCompensation($this, ['employee_number' => 'EMP-ORIGINAL']);
    $preview = $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->json();
    $saved = $this->postJson($this->base.'automatic', [...$this->input, 'request_id' => (string) Str::uuid(), 'calculation_hash' => $preview['calculation_hash']])->assertCreated()->json();
    $this->postJson($this->base.'payroll/'.$saved['id'].'/action', ['action' => 'submit', 'revision' => 1, 'reason' => 'Calculated draft checked'])->assertOk();
    SecurityHelper::authenticateWithPermissions($this->permissions)->refresh();
    $this->postJson($this->base.'payroll/'.$saved['id'].'/action', ['action' => 'approve', 'revision' => 2, 'reason' => 'Profile and statutory trace checked'])->assertOk();
    $this->actingAs($this->user);
    $next = $this->postJson($this->base.'automatic/preview', [...$this->input, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'pay_date' => '2026-11-30'])->assertOk()->json();
    expect($next['automatic']['ytd']['taxable'])->toBe(143000)->and($next['automatic']['ytd']['epf'])->toBe(15900);
    $this->employee->update(['job_title' => 'Changed role']);
    saveCompensation($this, ['employee_number' => 'EMP-NEW']);
    $this->get($this->base.'payslip/'.$saved['id'])->assertOk()->assertSee('1,430.00')->assertSee('Original role')->assertSee('EMP-ORIGINAL')->assertDontSee('Changed role')->assertDontSee('EMP-NEW')->assertDontSee('900302101234');
});

it('does not silently omit overtime or reveal payroll setup to an ordinary employee', function (): void {
    saveCompensation($this);
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'kind' => 'attendance', 'state' => 'approved', 'creator_id' => $this->user->id, 'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 18:00:00', 'data' => ['overtime_minutes' => 60]]);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertUnprocessable();
    $this->employee->update(['user_id' => $this->user->id]);
    $this->user->syncPermissions([]);
    $this->getJson($this->base.'compensation/form?employee_id='.$this->employee->id)->assertForbidden();
});

it('retains EIS eligibility after age 57 when approved earlier payroll proves prior contributions', function (): void {
    saveCompensation($this, ['date_of_birth' => '1969-03-02', 'eis_prior_contribution' => 'no']);
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'kind' => 'payroll', 'state' => 'approved', 'creator_id' => $this->user->id, 'starts_at' => '2026-02-01 00:00:00', 'ends_at' => '2026-02-28 23:59:59', 'data' => ['period_to' => '2026-02-28', 'pay_date' => '2026-02-28', 'automatic' => [], 'lines' => [['label' => 'EIS employee', 'category' => 'deduction', 'amount_minor' => 610]]]]);
    $preview = $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->json();
    expect(collect($preview['lines'])->firstWhere('label', 'EIS employee')['amount_minor'])->toBe(290);
});

it('uses the latest saved effective version without approval and preserves earlier versions', function (): void {
    $first = saveCompensation($this);
    $latest = saveCompensation($this, ['monthly_base' => '6200.00', 'employee_number' => 'EMP-42']);
    $this->postJson($this->base.'automatic/preview', $this->input)->assertOk()->assertJsonPath('base_minor', 260000)->assertJsonPath('payslip_snapshot.employee_number', 'EMP-42');
    expect(HrRecord::findOrFail($first)->data['monthly_base'])->toBe('3100.00');
    $this->postJson($this->base.'compensation/'.$latest.'/action', ['revision' => 1, 'action' => 'approve', 'reason' => 'No profile approval needed'])->assertForbidden();
    $this->get($this->base.'payslip-template')->assertOk()->assertSee('LAYOUT SAMPLE ONLY')->assertSee('3,486.61');
});
