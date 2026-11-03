<?php

use App\Models\BusinessEvent;
use App\Services\BusinessWorkspace;
use App\Services\NativeHr;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    TestBootstrapHelper::ensurePluginInstalled('employees');
    config(['project-progress.enabled' => true]);
});

function nativeHrUser(array $permissions = []): User
{
    $permissions = $permissions ?: ['view_any_employee_employee', 'view_employee_employee', 'create_employee_employee', 'update_employee_employee', 'view_any_employee_department', 'view_employee_department', 'create_employee_department', 'update_employee_department'];
    $user = SecurityHelper::authenticateWithPermissions($permissions)->refresh();
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();

    return $user;
}

function nativeHrPayload($user, array $values = []): array
{
    return ['request_id' => (string) Str::uuid(), 'values' => ['name' => 'V2 Native Employee', 'company_id' => $user->default_company_id, 'is_active' => true, 'work_email' => 'native@example.test', 'identification_id' => 'PRIVATE-IDENTITY-123', ...$values]];
}

it('keeps native HR authenticated, permission checked and feature gated', function (): void {
    $url = '/admin/v2/project-progress/api/hr/employees/form';
    $this->getJson($url)->assertUnauthorized();
    SecurityHelper::authenticateWithPermissions([])->refresh();
    $this->getJson($url)->assertForbidden();
    config(['project-progress.enabled' => false]);
    $this->getJson($url)->assertNotFound();
});

it('creates an employee and its original partner with encrypted immutable audit data', function (): void {
    $user = nativeHrUser();
    $payload = nativeHrPayload($user);
    $result = $this->postJson('/admin/v2/project-progress/api/hr/employees', $payload)->assertCreated()->assertJsonPath('values.work_email', 'native@example.test')->assertJsonPath('can_edit', true)->json();
    $employee = Employee::findOrFail($result['id']);
    expect($employee->creator_id)->toBe($user->id)->and($employee->partner->email)->toBe('native@example.test');
    $event = BusinessEvent::query()->sole();
    expect($event->changes['after']['identification_id'])->toBe('PRIVATE-IDENTITY-123')
        ->and($event->getRawOriginal('changes'))->not->toContain('PRIVATE-IDENTITY-123');
    expect(fn () => $event->update(['action' => 'changed']))->toThrow(LogicException::class);
    $this->getJson('/admin/v2/project-progress/api/hr/employees/'.$employee->id)->assertOk()->assertJsonPath('values.identification_id', 'PRIVATE-IDENTITY-123');
});

it('retries creation without duplicate employees or contacts and rejects changed key reuse', function (): void {
    $user = nativeHrUser();
    $payload = nativeHrPayload($user);
    $first = $this->postJson('/admin/v2/project-progress/api/hr/employees', $payload)->assertCreated()->json('id');
    $this->postJson('/admin/v2/project-progress/api/hr/employees', $payload)->assertCreated()->assertJsonPath('id', $first);
    expect(Employee::where('name', 'V2 Native Employee')->count())->toBe(1)->and(BusinessEvent::count())->toBe(1);
    $payload['values']['name'] = 'Changed request';
    $this->postJson('/admin/v2/project-progress/api/hr/employees', $payload)->assertConflict();
});

it('updates inside V2 and detects stale revisions even within the same second', function (): void {
    $user = nativeHrUser();
    $created = $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user))->assertCreated()->json();
    $url = '/admin/v2/project-progress/api/hr/employees/'.$created['id'];
    $values = $created['values'];
    $values['name'] = 'Updated V2 Employee';
    $values['work_email'] = 'updated@example.test';
    $this->putJson($url, ['revision' => $created['revision'], 'values' => $values])->assertOk()->assertJsonPath('values.name', 'Updated V2 Employee');
    $this->putJson($url, ['revision' => $created['revision'], 'values' => $values])->assertConflict();
    expect(Employee::find($created['id'])->partner->email)->toBe('updated@example.test')->and(BusinessEvent::count())->toBe(2);
});

it('rejects unknown identity-link fields and foreign company organisation references atomically', function (): void {
    $user = nativeHrUser();
    $other = Company::factory()->create(['partner_id' => Partner::factory()]);
    $department = Department::factory()->create(['company_id' => $other->id]);
    $base = '/admin/v2/project-progress/api/hr/employees';
    $this->postJson($base, nativeHrPayload($user, ['user_id' => $user->id]))->assertUnprocessable();
    $this->postJson($base, nativeHrPayload($user, ['department_id' => $department->id]))->assertUnprocessable();
    $this->postJson($base, nativeHrPayload($user, ['company_id' => $other->id]))->assertUnprocessable();
    expect(BusinessEvent::count())->toBe(0)->and(Employee::where('name', 'V2 Native Employee')->count())->toBe(0);
});

it('enforces read-only profiles and does not reveal foreign company private data', function (): void {
    $user = nativeHrUser();
    $record = $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user))->assertCreated()->json();
    $reader = nativeHrUser(['view_any_employee_employee', 'view_employee_employee']);
    $this->getJson('/admin/v2/project-progress/api/hr/employees/'.$record['id'])->assertOk()->assertJsonPath('can_edit', false);
    $this->putJson('/admin/v2/project-progress/api/hr/employees/'.$record['id'], ['values' => $record['values'], 'revision' => $record['revision']])->assertForbidden();
    $other = Company::factory()->create(['partner_id' => Partner::factory()]);
    $foreign = Employee::withoutEvents(fn () => Employee::factory()->create(['company_id' => $other->id, 'user_id' => null, 'departure_reason_id' => null]));
    $this->getJson('/admin/v2/project-progress/api/hr/employees/'.$foreign->id)->assertNotFound();
});

it('keeps existing ownership restrictions when update permission alone is granted', function (): void {
    $user = nativeHrUser();
    $record = $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user))->assertCreated()->json();
    $user->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    $this->getJson('/admin/v2/project-progress/api/hr/employees/'.$record['id'])->assertOk()->assertJsonPath('can_edit', false);
    $this->putJson('/admin/v2/project-progress/api/hr/employees/'.$record['id'], ['values' => $record['values'], 'revision' => $record['revision']])->assertForbidden();
});

it('creates departments natively and rejects circular hierarchies without changing records', function (): void {
    $user = nativeHrUser();
    $base = '/admin/v2/project-progress/api/hr/departments';
    $root = $this->postJson($base, ['request_id' => (string) Str::uuid(), 'values' => ['name' => 'V2 Operations', 'company_id' => $user->default_company_id]])->assertCreated()->json();
    $child = $this->postJson($base, ['request_id' => (string) Str::uuid(), 'values' => ['name' => 'V2 Site Team', 'company_id' => $user->default_company_id, 'parent_id' => $root['id']]])->assertCreated()->json();
    $this->putJson($base.'/'.$root['id'], ['revision' => $root['revision'], 'values' => [...$root['values'], 'parent_id' => $child['id']]])->assertUnprocessable();
    expect(Department::find($root['id'])->parent_id)->toBeNull()->and(BusinessEvent::count())->toBe(2);
});

it('removes old ERP redirects for native HR sections', function (): void {
    nativeHrUser();
    $this->getJson('/admin/v2/project-progress/api/modules/people')->assertOk()->assertJsonPath('sections.0.native', true)->assertJsonPath('sections.0.url', null)->assertJsonPath('sections.0.create_url', null)->assertJsonPath('sections.0.can_create', true);
});

it('matches coach ownership to the linked user instead of the employee primary key', function (): void {
    $user = nativeHrUser();
    $user->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    $coach = Employee::withoutEvents(fn () => Employee::factory()->create(['company_id' => $user->default_company_id, 'user_id' => $user->id, 'departure_reason_id' => null]));
    $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user, ['coach_id' => $coach->id]))->assertCreated()->assertJsonPath('can_edit', true);
    $foreignCoach = Employee::withoutEvents(fn () => Employee::factory()->create(['company_id' => $user->default_company_id, 'user_id' => null, 'departure_reason_id' => null]));
    $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user, ['coach_id' => $foreignCoach->id]))->assertCreated()->assertJsonPath('can_edit', false);
});

it('does not grant edits through an employee-user primary key collision and respects group membership', function (): void {
    $user = nativeHrUser();
    $user->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    $other = User::withoutEvents(fn () => User::factory()->create());
    $coach = new Employee;
    $coach->id = $user->id;
    $coach->setRelation('user', $other);
    $employee = (new Employee)->setRelation('coach', $coach);
    expect($user->can('update', $employee))->toBeFalse();
    $user->forceFill(['resource_permission' => PermissionType::GROUP])->saveQuietly();
    expect($user->can('update', $employee))->toBeFalse();
    $team = Team::query()->create(['name' => 'HR test group']);
    $team->users()->attach([$user->id, $other->id]);
    expect($user->can('update', $employee))->toBeTrue();
});

it('validates required legacy marital status instead of returning a database exception', function (): void {
    $user = nativeHrUser();
    $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user, ['marital' => null]))->assertUnprocessable()->assertJsonValidationErrors('values.marital');
    $this->getJson('/admin/v2/project-progress/api/hr/employees/form')->assertOk()->assertJsonPath('defaults.marital', 'single');
});

it('does not disclose private values in persistence errors', function (): void {
    $user = nativeHrUser();
    Log::spy();
    $hr = Mockery::mock(NativeHr::class, [app(BusinessWorkspace::class)])->makePartial();
    $hr->shouldReceive('save')->andThrow(new QueryException('pgsql', 'insert into employees (identification_id) values (?)', ['SENSITIVE-PRIVATE-VALUE'], new PDOException('SENSITIVE-PRIVATE-VALUE')));
    app()->instance(NativeHr::class, $hr);
    $response = $this->postJson('/admin/v2/project-progress/api/hr/employees', nativeHrPayload($user))->assertStatus(500);
    expect(json_encode($response->json()))->not->toContain('SENSITIVE-PRIVATE-VALUE', 'insert into');
    Log::shouldHaveReceived('error')->with('Native HR persistence failed', Mockery::on(fn ($metadata): bool => ! str_contains(json_encode($metadata), 'SENSITIVE-PRIVATE-VALUE')))->once();
});
