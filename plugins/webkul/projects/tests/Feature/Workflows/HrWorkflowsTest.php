<?php

use App\Models\BusinessEvent;
use App\Models\HrRecord;
use App\Services\HrWorkflows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\Project\Models\Project;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    TestBootstrapHelper::ensurePluginInstalled('employees');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    config(['project-progress.enabled' => true]);
    Storage::fake('local');
    $this->permissions = collect(['attendance', 'payroll', 'documents', 'expenses'])->flatMap(fn ($kind) => ['v2_hr_manage_'.$kind, 'v2_hr_approve_'.$kind])->all();
    $this->user = SecurityHelper::authenticateWithPermissions($this->permissions)->refresh();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->user->default_company_id, 'is_active' => true]);
    $this->project = Project::factory()->create(['company_id' => $this->user->default_company_id, 'user_id' => $this->user->id, 'is_active' => true]);
    $this->base = '/admin/v2/project-progress/api/hr-workflows/';
});

function hrWorkflowPayload($test, array $values): array
{
    return ['request_id' => (string) Str::uuid(), 'values' => ['company_id' => $test->user->default_company_id, 'employee_id' => $test->employee->id, ...$values]];
}

function hrAttendanceValues($test): array
{
    return ['project_id' => $test->project->id, 'starts_at' => '2026-01-05T08:00', 'ends_at' => '2026-01-05T17:00', 'timezone' => 'Asia/Kuala_Lumpur', 'break_minutes' => 60, 'overtime_minutes' => 60, 'notes' => 'Synthetic site attendance'];
}

function hrRulesPayload($test): array
{
    return ['request_id' => (string) Str::uuid(), 'values' => ['company_id' => $test->user->default_company_id, 'title' => 'Malaysia configurable v1', 'jurisdiction' => 'Malaysia', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'components' => [['label' => 'EPF employee', 'category' => 'deduction', 'method' => 'manual', 'value' => '0.00'], ['label' => 'Allowance', 'category' => 'earning', 'method' => 'percent_base', 'value' => '5.00'], ['label' => 'EPF employer', 'category' => 'employer', 'method' => 'manual', 'value' => '0.00']]]];
}

it('records attendance with exact minutes, overlap protection and retry receipts', function (): void {
    $payload = hrWorkflowPayload($this, hrAttendanceValues($this));
    $created = $this->postJson($this->base.'attendance', $payload)->assertCreated()->assertJsonPath('values.worked_minutes', 480)->assertJsonPath('values.regular_minutes', 420)->json();
    $this->postJson($this->base.'attendance', $payload)->assertCreated()->assertJsonPath('id', $created['id']);
    $payload['request_id'] = (string) Str::uuid();
    $this->postJson($this->base.'attendance', $payload)->assertUnprocessable();
    expect(HrRecord::count())->toBe(1)->and(BusinessEvent::where('kind', 'hr-attendance')->count())->toBe(1);
});

it('rejects invalid shifts, excess overtime and project/company mismatches', function (): void {
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'break_minutes' => 600]))->assertUnprocessable();
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'overtime_minutes' => 900]))->assertUnprocessable();
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'project_id' => 999999]))->assertUnprocessable();
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'state' => 'approved']))->assertUnprocessable();
});

it('requires independent review and rejects stale approval revisions', function (): void {
    $created = $this->postJson($this->base.'attendance', hrWorkflowPayload($this, hrAttendanceValues($this)))->assertCreated()->assertJsonPath('actions', ['withdraw'])->json();
    $action = ['revision' => 1, 'action' => 'approve', 'reason' => 'Supervisor verified'];
    $this->postJson($this->base.'attendance/'.$created['id'].'/action', $action)->assertForbidden();
    $reviewer = SecurityHelper::authenticateWithPermissions($this->permissions)->refresh();
    $this->postJson($this->base.'attendance/'.$created['id'].'/action', $action)->assertOk()->assertJsonPath('state', 'approved');
    $this->postJson($this->base.'attendance/'.$created['id'].'/action', $action)->assertConflict();
    expect(HrRecord::find($created['id'])->reviewer_id)->toBe($reviewer->id);
});

it('stores private documents and authorizes each download', function (): void {
    $values = hrWorkflowPayload($this, ['title' => 'Private permit', 'category' => 'permit', 'expires_on' => now()->addDays(10)->toDateString()]);
    $created = $this->post($this->base.'documents', [...$values, 'attachment' => UploadedFile::fake()->create('permit.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('values.file_path')->assertJsonPath('expiry_status', 'Due within 30 days')->json();
    $this->get($this->base.'documents/'.$created['id'].'/download')->assertOk();
    SecurityHelper::authenticateWithPermissions([]);
    $this->getJson($this->base.'documents/'.$created['id'].'/download')->assertForbidden();
    $record = HrRecord::find($created['id']);
    expect($record->getRawOriginal('data'))->not->toContain('Private permit');
    Storage::disk('local')->assertExists($record->data['file_path']);
});

it('rejects unsupported or missing uploads and requires expense receipt and positive amount', function (): void {
    $values = hrWorkflowPayload($this, ['title' => 'Contract', 'category' => 'contract', 'expires_on' => '2027-01-01']);
    $this->postJson($this->base.'documents', $values)->assertUnprocessable();
    $this->post($this->base.'documents', [...$values, 'attachment' => UploadedFile::fake()->create('script.html', 10, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
    $expense = hrWorkflowPayload($this, ['title' => 'Site transport', 'date' => '2026-01-05', 'project_id' => $this->project->id, 'amount' => '12.50', 'notes' => 'Client visit']);
    $this->post($this->base.'expenses', [...$expense, 'attachment' => UploadedFile::fake()->image('receipt.png')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('values.amount_minor', 1250);
});

it('tracks onboarding and offboarding checklist items with revision checks', function (): void {
    $created = $this->postJson($this->base.'checklists', hrWorkflowPayload($this, ['title' => 'Onboarding', 'process' => 'onboarding', 'due_on' => '2026-12-01', 'items' => ['Verify permit', 'Issue equipment']]))->assertCreated()->json();
    $url = $this->base.'checklists/'.$created['id'].'/action';
    $this->postJson($url, ['action' => 'complete', 'item' => 0, 'revision' => 1, 'reason' => 'Permit verified'])->assertOk()->assertJsonPath('values.completed', [0]);
    $this->postJson($url, ['action' => 'complete', 'item' => 1, 'revision' => 1, 'reason' => 'Stale'])->assertConflict();
    $this->postJson($url, ['action' => 'reopen', 'item' => 0, 'revision' => 2, 'reason' => 'New permit needed'])->assertOk()->assertJsonPath('values.completed', []);
});

it('calculates configurable payroll precisely and keeps employer contributions separate', function (): void {
    $result = app(HrWorkflows::class)->calculatePayroll(['base_pay' => '3000.00', 'components' => [['label' => 'EPF employee', 'value' => '330.00'], ['label' => 'EPF employer', 'value' => '390.00']]], hrRulesPayload($this)['values'], 2);
    expect($result['gross_minor'])->toBe(315000)->and($result['deductions_minor'])->toBe(33000)->and($result['net_minor'])->toBe(282000)->and($result['employer_minor'])->toBe(39000);
    expect(fn () => app(HrWorkflows::class)->minor('0.001', 2))->toThrow(ValidationException::class);
});

it('requires approved rule versions, statutory verification and payroll review before payslip issue', function (): void {
    $rules = $this->postJson($this->base.'payroll-rules', hrRulesPayload($this))->assertCreated()->json();
    $payload = hrWorkflowPayload($this, ['rules_id' => $rules['id'], 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'pay_date' => '2026-02-01', 'base_pay' => '3000.00', 'statutory_verified' => true, 'components' => [['label' => 'EPF employee', 'category' => 'deduction', 'method' => 'manual', 'value' => '330.00'], ['label' => 'EPF employer', 'category' => 'employer', 'method' => 'manual', 'value' => '390.00']]]);
    $this->postJson($this->base.'payroll', $payload)->assertNotFound();
    $reviewer = SecurityHelper::authenticateWithPermissions($this->permissions)->refresh();
    $this->postJson($this->base.'payroll-rules/'.$rules['id'].'/action', ['revision' => 1, 'action' => 'approve', 'reason' => 'Rules checked'])->assertOk();
    $this->actingAs($this->user);
    $created = $this->postJson($this->base.'payroll', $payload)->assertCreated()->assertJsonPath('state', 'draft')->assertJsonPath('values.net_minor', 282000)->json();
    $this->getJson($this->base.'payslip/'.$created['id'])->assertConflict();
    $this->postJson($this->base.'payroll/'.$created['id'].'/action', ['revision' => 1, 'action' => 'submit', 'reason' => 'Prepared for review'])->assertOk();
    $this->actingAs($reviewer);
    $this->postJson($this->base.'payroll/'.$created['id'].'/action', ['revision' => 2, 'action' => 'approve', 'reason' => 'Totals and statutory values checked'])->assertOk();
    $this->actingAs($this->user);
    $this->get($this->base.'payslip/'.$created['id'])->assertOk()->assertSee('2,820.00');
    $this->postJson($this->base.'payroll', [...$payload, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
});

it('isolates self service without requiring employee administration permissions', function (): void {
    $this->user->syncPermissions([]);
    $this->getJson($this->base.'self-service')->assertOk()->assertJsonPath('profile.name', $this->employee->name);
    $form = $this->getJson($this->base.'attendance/form?self=1')->assertOk()->assertJsonPath('can_create', true)->json();
    expect($form['lookups']['employee_id'])->toHaveCount(1);
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, hrAttendanceValues($this)))->assertCreated();
    $other = Employee::factory()->create(['company_id' => $this->user->default_company_id, 'is_active' => true]);
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'employee_id' => $other->id]))->assertForbidden();
    $this->getJson($this->base.'payroll-rules/form')->assertForbidden();
});

it('allows own leave submission with identity derived only from the login', function (): void {
    $type = LeaveType::create(['name' => 'Self-service leave', 'company_id' => $this->user->default_company_id, 'is_active' => true, 'requires_allocation' => 'no', 'leave_validation_type' => 'hr', 'request_unit' => 'day']);
    $this->user->syncPermissions([]);
    $payload = ['request_id' => (string) Str::uuid(), 'values' => ['holiday_status_id' => $type->id, 'request_date_from' => '2027-01-04', 'request_date_to' => '2027-01-04', 'request_unit_half' => false, 'request_date_from_period' => 'morning', 'private_name' => 'My request']];
    $created = $this->postJson($this->base.'self-service/leave', $payload)->assertCreated()->json();
    expect(Leave::find($created['id'])->employee_id)->toBe($this->employee->id);
    $this->postJson($this->base.'self-service/leave', $payload)->assertCreated()->assertJsonPath('id', $created['id']);
    $payload['values']['employee_id'] = 9999;
    $this->postJson($this->base.'self-service/leave', $payload)->assertUnprocessable();
});

it('denies unlinked, inactive and unauthenticated accounts and preserves the feature flag', function (): void {
    $this->employee->update(['is_active' => false]);
    $this->getJson($this->base.'self-service')->assertForbidden();
    $this->user->update(['is_active' => false]);
    $this->getJson($this->base.'attendance')->assertForbidden();
    config(['project-progress.enabled' => false]);
    $this->getJson($this->base.'attendance')->assertNotFound();
});

it('restricts self profile updates and checks profile revisions', function (): void {
    $this->user->syncPermissions([]);
    $profile = $this->getJson($this->base.'self-service')->assertOk()->json();
    $payload = ['revision' => $profile['revision'], 'values' => ['private_phone' => '0123456789']];
    $this->putJson($this->base.'self-service/profile', $payload)->assertOk();
    expect($this->employee->refresh()->private_phone)->toBe('0123456789');
    $this->putJson($this->base.'self-service/profile', $payload)->assertConflict();
    $profile = $this->getJson($this->base.'self-service')->assertOk()->json();
    $this->putJson($this->base.'self-service/profile', ['revision' => $profile['revision'], 'values' => ['user_id' => 999]])->assertUnprocessable();
});

it('rolls back uploaded documents when the audit receipt cannot be saved', function (): void {
    BusinessEvent::creating(fn () => throw new RuntimeException('Audit unavailable'));
    $this->withoutExceptionHandling();
    try {
        $this->post($this->base.'documents', [...hrWorkflowPayload($this, ['title' => 'Rollback permit', 'category' => 'permit', 'expires_on' => '2027-01-01']), 'attachment' => UploadedFile::fake()->create('permit.pdf', 20, 'application/pdf')], ['Accept' => 'application/json']);
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Audit unavailable');
    } finally {
        BusinessEvent::flushEventListeners();
        $method = new ReflectionMethod(BusinessEvent::class, 'booted');
        $method->invoke(null);
    }
    expect(HrRecord::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('hides payroll drafts and other employee records from self-service', function (): void {
    $other = Employee::factory()->create(['company_id' => $this->user->default_company_id, 'is_active' => true]);
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $other->id, 'kind' => 'payroll', 'state' => 'approved', 'creator_id' => $this->user->id, 'data' => ['title' => 'Private other employee salary']]);
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'kind' => 'payroll', 'state' => 'draft', 'creator_id' => $this->user->id, 'data' => ['title' => 'Own unpublished draft']]);
    $this->getJson($this->base.'payroll?self=1')->assertOk()->assertJsonPath('rows', []);
    $this->user->syncPermissions([]);
    $this->getJson($this->base.'payroll')->assertOk()->assertJsonPath('rows', []);
});

it('shows document expiry reminders and rejects foreign-company records', function (): void {
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'kind' => 'documents', 'state' => 'approved', 'creator_id' => $this->user->id, 'due_on' => now()->subDay(), 'data' => ['title' => 'Expired permit', 'expires_on' => now()->subDay()->toDateString()]]);
    HrRecord::create(['company_id' => $this->user->default_company_id, 'employee_id' => $this->employee->id, 'kind' => 'documents', 'state' => 'approved', 'creator_id' => $this->user->id, 'due_on' => now()->addYear(), 'data' => ['title' => 'Future contract', 'expires_on' => now()->addYear()->toDateString()]]);
    $this->getJson($this->base.'documents?expiry=expired')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.values.title', 'Expired permit');
    $this->postJson($this->base.'attendance', hrWorkflowPayload($this, [...hrAttendanceValues($this), 'company_id' => 999999]))->assertForbidden();
});

it('allows a creator to withdraw pending attendance and resubmit a corrected shift', function (): void {
    $payload = hrWorkflowPayload($this, hrAttendanceValues($this));
    $created = $this->postJson($this->base.'attendance', $payload)->assertCreated()->json();
    $this->postJson($this->base.'attendance/'.$created['id'].'/action', ['action' => 'withdraw', 'revision' => 1, 'reason' => 'Correcting the recorded break'])->assertOk()->assertJsonPath('state', 'withdrawn');
    $payload['request_id'] = (string) Str::uuid();
    $payload['values']['break_minutes'] = 30;
    $this->postJson($this->base.'attendance', $payload)->assertCreated()->assertJsonPath('values.worked_minutes', 510);
});
