<?php

use App\Models\BusinessEvent;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    config(['project-progress.enabled' => true]);
    $this->actor = SecurityHelper::authenticateWithPermissions(['view_any_time_off_time::off', 'view_time_off_time::off', 'create_time_off_time::off', 'update_time_off_time::off', 'view_any_employee_employee', 'view_employee_employee'])->refresh();
    $this->actor->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    $this->approver = Employee::factory()->create(['company_id' => $this->actor->default_company_id, 'user_id' => $this->actor->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->actor->default_company_id, 'user_id' => null, 'parent_id' => $this->approver->id, 'is_active' => true]);
    $this->type = LeaveType::create(['name' => 'V2 Test Leave', 'company_id' => $this->actor->default_company_id, 'is_active' => true, 'requires_allocation' => 'no', 'leave_validation_type' => 'hr', 'request_unit' => 'day']);
    $this->payload = ['request_id' => (string) Str::uuid(), 'values' => ['company_id' => $this->actor->default_company_id, 'employee_id' => $this->employee->id, 'holiday_status_id' => $this->type->id, 'request_date_from' => '2027-01-04', 'request_date_to' => '2027-01-08', 'request_unit_half' => false, 'request_date_from_period' => 'morning', 'private_name' => 'Private leave reason']];
    $this->url = '/admin/v2/project-progress/api/hr/leave';
});

it('creates native leave with calculated duration and safely retries', function (): void {
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('duration', '5 working days')->json();
    $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('id', $created['id']);
    expect(Leave::find($created['id'])->state)->toBe(State::CONFIRM)->and(BusinessEvent::where('kind', 'leave')->count())->toBe(1);
    expect(BusinessEvent::where('kind', 'leave')->first()->getRawOriginal('changes'))->not->toContain('Private leave reason');
    $this->getJson($this->url.'/form')->assertOk()->assertJsonPath('can_create', true);
});

it('rejects overlapping leave, reversed dates and unsupported values', function (): void {
    $this->postJson($this->url, $this->payload)->assertCreated();
    $this->payload['request_id'] = (string) Str::uuid();
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    $this->payload['values']['request_date_to'] = '2027-01-01';
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    $this->payload['values']['state'] = 'validate_two';
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
});

it('edits pending requests without counting the request against its allocation twice', function (): void {
    $this->type->update(['requires_allocation' => 'yes']);
    LeaveAllocation::create(['employee_id' => $this->employee->id, 'holiday_status_id' => $this->type->id, 'company_id' => $this->actor->default_company_id, 'state' => 'validate_two', 'date_from' => '2027-01-01', 'date_to' => '2027-12-31', 'number_of_days' => 5]);
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->json();
    $values = $created['values'];
    $values['private_name'] = 'Updated description';
    $this->putJson($this->url.'/'.$created['id'], ['values' => $values, 'revision' => $created['revision']])->assertOk();
    $this->putJson($this->url.'/'.$created['id'], ['values' => $values, 'revision' => $created['revision']])->assertConflict();
});

it('rejects insufficient or expired allocation and unsupported required documents', function (): void {
    $this->type->update(['requires_allocation' => 'yes']);
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    LeaveAllocation::create(['employee_id' => $this->employee->id, 'holiday_status_id' => $this->type->id, 'company_id' => $this->actor->default_company_id, 'state' => 'validate_two', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'number_of_days' => 20]);
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    $this->type->update(['requires_allocation' => 'no', 'support_document' => true]);
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
});

it('approves through a reasoned audited decision and rejects stale decisions and edits', function (): void {
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('actions.0', 'approve')->json();
    $decision = ['action' => 'approve', 'revision' => $created['revision'], 'reason' => 'Reviewed staffing'];
    $this->postJson($this->url.'/'.$created['id'].'/decision', $decision)->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('actions', []);
    $this->postJson($this->url.'/'.$created['id'].'/decision', $decision)->assertConflict();
    expect(Leave::find($created['id'])->state)->toBe(State::VALIDATE_TWO)->and(BusinessEvent::where('action', 'approve')->count())->toBe(1);
});

it('requires distinct manager and officer approvals when configured', function (): void {
    $this->type->update(['leave_validation_type' => 'both']);
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->json();
    $first = $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'approve', 'revision' => $created['revision'], 'reason' => 'Manager approved'])->assertOk()->assertJsonPath('actions', [])->json();
    expect(Leave::find($created['id'])->state)->toBe(State::VALIDATE_ONE);
    $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'approve', 'revision' => $first['revision'], 'reason' => 'Same reviewer again'])->assertForbidden();
});

it('prevents self approval and rejects mismatched company references', function (): void {
    $this->payload['values']['employee_id'] = $this->approver->id;
    $this->approver->update(['is_active' => true]);
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('actions', [])->json();
    $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'approve', 'revision' => $created['revision'], 'reason' => 'Own request'])->assertForbidden();
    $this->payload['request_id'] = (string) Str::uuid();
    $this->payload['values']['company_id'] = 999999;
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
});

it('uses linked employee user ownership rather than employee primary keys', function (): void {
    $leave = Leave::create(['employee_id' => $this->employee->id, 'company_id' => $this->actor->default_company_id, 'state' => 'confirm']);
    $this->actor->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    expect($this->actor->can('update', $leave))->toBeTrue();
    $this->employee->update(['parent_id' => null]);
    $leave->unsetRelation('employee');
    expect($this->actor->can('update', $leave))->toBeFalse();
    $this->approver->update(['user_id' => null]);
    $this->employee->update(['user_id' => $this->actor->id]);
    $leave->unsetRelation('employee');
    expect($this->actor->can('update', $leave))->toBeTrue();
});

it('calculates half days, refuses weekend-only dates and audits refusal', function (): void {
    $this->payload['values']['request_date_to'] = '2027-01-04';
    $this->payload['values']['request_unit_half'] = true;
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('duration', '0.5 day')->json();
    $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'refuse', 'revision' => $created['revision'], 'reason' => 'Staffing conflict'])->assertOk()->assertJsonPath('can_edit', false);
    expect(Leave::find($created['id'])->state)->toBe(State::REFUSE);
    $this->payload['request_id'] = (string) Str::uuid();
    $this->payload['values']['request_date_from'] = '2027-01-09';
    $this->payload['values']['request_date_to'] = '2027-01-09';
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
});

it('completes the second stage with a distinct assigned officer', function (): void {
    $this->type->update(['leave_validation_type' => 'both']);
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->json();
    $first = $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'approve', 'revision' => $created['revision'], 'reason' => 'Manager approved'])->assertOk()->json();
    $officer = SecurityHelper::authenticateWithPermissions(['view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off'])->refresh();
    $officer->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    $officerEmployee = Employee::factory()->create(['company_id' => $officer->default_company_id, 'user_id' => $officer->id]);
    $this->type->notifiedTimeOffOfficers()->attach($officer->id);
    $this->postJson($this->url.'/'.$created['id'].'/decision', ['action' => 'approve', 'revision' => $first['revision'], 'reason' => 'HR reviewed'])->assertOk();
    expect(Leave::find($created['id'])->state)->toBe(State::VALIDATE_TWO)->and(Leave::find($created['id'])->second_approver_id)->toBe($officerEmployee->id);
});

it('automatically validates only leave types explicitly configured without approval', function (): void {
    $this->type->update(['leave_validation_type' => 'no_validation']);
    $created = $this->postJson($this->url, $this->payload)->assertCreated()->assertJsonPath('actions', [])->assertJsonPath('can_edit', false)->json();
    expect(Leave::find($created['id'])->state)->toBe(State::VALIDATE_TWO);
});

it('keeps native leave routes authenticated and read-only accounts from writing', function (): void {
    SecurityHelper::authenticateWithPermissions(['view_any_time_off_time::off', 'view_time_off_time::off']);
    $this->postJson($this->url, $this->payload)->assertForbidden();
    config(['project-progress.enabled' => false]);
    $this->getJson($this->url.'/form')->assertNotFound();
});
