<?php

namespace App\Services;

use App\Models\BusinessEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Traits\TimeOffHelper;

class NativeLeave
{
    use TimeOffHelper;

    public function fields(): array
    {
        return [
            ['company_id', 'Company', 'select', 'Leave request', true, 'companies'],
            ['employee_id', 'Employee', 'select', 'Leave request', true, 'employees'],
            ['holiday_status_id', 'Leave type', 'select', 'Leave request', true, 'leave_types'],
            ['request_date_from', 'From', 'date', 'Dates', true],
            ['request_date_to', 'To', 'date', 'Dates', true],
            ['request_unit_half', 'Half day (same date only)', 'checkbox', 'Dates'],
            ['request_date_from_period', 'Half-day period', 'select', 'Dates', false, 'periods'],
            ['private_name', 'Description', 'text', 'Leave request'],
        ];
    }

    public function lookups(User $user): array
    {
        $hr = app(NativeHr::class);
        $ids = $hr->companyIds($user);

        return [
            'companies'   => $user->allowedCompanies()->whereKey($ids)->get(['companies.id', 'companies.name'])->toArray(),
            'employees'   => Employee::query()->whereIn('company_id', $ids)->where('is_active', true)->get()->filter(fn ($employee) => $user->can('view', $employee))->map(fn ($employee) => $employee->only(['id', 'name', 'company_id']))->values()->all(),
            'leave_types' => LeaveType::query()->where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))->where('is_active', true)->get(['id', 'name', 'company_id'])->toArray(),
            'periods'     => [['id' => 'morning', 'name' => 'Morning'], ['id' => 'afternoon', 'name' => 'Afternoon']],
        ];
    }

    public function actions(User $user, Leave $leave): array
    {
        if (! $user->can('update', $leave) || $leave->employee?->user_id === $user->id || ! $user->employee || (int) $user->employee->company_id !== (int) $leave->company_id) {
            return [];
        }
        $mode = $leave->holidayStatus?->leave_validation_type?->value;
        $manager = $leave->employee?->parent?->user_id === $user->id;
        $officer = $leave->holidayStatus?->notifiedTimeOffOfficers()->whereKey($user->id)->exists() || $user->resource_permission === PermissionType::GLOBAL;
        $allowed = match ($mode) {
            'manager' => $manager,
            'hr'      => $officer,
            'both'    => $leave->state === State::CONFIRM ? $manager : ($officer && $leave->first_approver_id !== $user->employee->id),
            default   => false,
        };

        return $allowed && in_array($leave->state, [State::CONFIRM, State::VALIDATE_ONE], true) ? ['approve', 'refuse'] : [];
    }

    public function save(User $user, array $data, ?int $id, bool $self = false): Leave
    {
        $hr = app(NativeHr::class);
        if ($self) {
            abort_unless(! $id && Package::isPluginInstalled('time-off') && Package::getPackagePlugin('time-off')?->is_active && app(HrWorkflows::class)->ownEmployee($user)?->id === (int) $data['employee_id'], 403);
        }

        return (new Leave)->getConnection()->transaction(function () use ($user, $data, $id, $hr, $self): Leave {
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && ($self || $hr->available($user, 'leave')), 403);
            $key = $data['request_id'] ?? null;
            $revision = $data['revision'] ?? null;
            unset($data['request_id'], $data['revision']);
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! $id && $receipt = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $key)->first()) {
                abort_unless($receipt->kind === 'leave' && hash_equals($receipt->payload_hash, $hash), 409, 'Submission key already used.');

                return $self ? Leave::query()->where('employee_id', $data['employee_id'])->findOrFail($receipt->record_id) : $hr->record($user, 'leave', $receipt->record_id);
            }
            $employee = Employee::query()->whereIn('company_id', $hr->companyIds($user))->where('company_id', $data['company_id'])->lockForUpdate()->findOrFail($data['employee_id']);
            if (! $self) {
                Gate::forUser($user)->authorize('view', $employee);
            }
            abort_unless($employee->is_active, 422, 'Choose an active employee.');
            $leave = $id ? $hr->record($user, 'leave', $id, true) : new Leave;
            if (! $self) {
                Gate::forUser($user)->authorize($id ? 'update' : 'create', $id ? $leave : Leave::class);
                Gate::forUser($user)->authorize('view', $leave);
            }
            if ($id) {
                abort_unless(hash_equals($hr->revision($leave), $revision), 409, 'This leave request changed. Reopen it before saving.');
                abort_unless($leave->state === State::CONFIRM, 409, 'Only pending requests can be edited.');
                abort_unless((int) $leave->employee_id === (int) $employee->id && (int) $leave->company_id === (int) $data['company_id'], 422, 'Employee and company cannot be changed.');
            }
            $type = LeaveType::query()->where(fn ($q) => $q->where('company_id', $employee->company_id)->orWhereNull('company_id'))->where('is_active', true)->findOrFail($data['holiday_status_id']);
            $this->validateRequest($employee, $type, $data, $id);
            $duration = $this->getDurationInfo($data);
            $before = $id ? $leave->getAttributes() : null;
            $leave->fill([...$data, 'user_id' => $employee->user_id, 'employee_company_id' => $employee->company_id, 'department_id' => $employee->department_id, 'calendar_id' => $employee->calendar_id, 'manager_id' => $employee->parent_id, 'date_from' => $data['request_date_from'], 'date_to' => $data['request_date_to'], 'number_of_days' => $duration['number_of_days'], 'duration_display' => $duration['duration_display'], 'state' => $type->leave_validation_type?->value === 'no_validation' ? State::VALIDATE_TWO : State::CONFIRM]);
            $leave->save();
            $leave->refresh();
            $this->audit($user, $leave, $id ? 'updated' : 'created', $before, $key, $hash);

            return $leave;
        });
    }

    private function validateRequest(Employee $employee, LeaveType $type, array $data, ?int $id): void
    {
        if ($type->request_unit === 'half_day' && ! $data['request_unit_half']) {
            throw ValidationException::withMessages(['request_unit_half' => 'This leave type requires a half-day request.']);
        }
        if ($type->request_unit === 'hour' || $type->allows_negative || $type->support_document || $type->include_public_holidays_in_duration) {
            throw ValidationException::withMessages(['holiday_status_id' => 'This type needs hourly, negative-balance, document or public-holiday handling. Use the existing ERP workflow until those options are supported in V2.']);
        }
        $start = Carbon::parse($data['request_date_from']);
        $end = Carbon::parse($data['request_date_to']);
        if ($data['request_unit_half'] && (! $start->equalTo($end) || $start->isWeekend())) {
            throw ValidationException::withMessages(['request_date_to' => 'Half-day requests require one working date.']);
        }
        $days = $this->getDurationInfo($data)['number_of_days'];
        if ($days <= 0) {
            throw ValidationException::withMessages(['request_date_from' => 'Choose at least one working day.']);
        }
        if (Leave::query()->where('employee_id', $employee->id)->where('state', '!=', State::REFUSE->value)->when($id, fn ($q) => $q->whereKeyNot($id))->whereDate('date_from', '<=', $end)->whereDate('date_to', '>=', $start)->exists()) {
            throw ValidationException::withMessages(['request_date_from' => 'This employee already has leave on these dates.']);
        }
        if ($type->requires_allocation === 'yes') {
            $allocated = LeaveAllocation::query()->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->where('state', State::VALIDATE_TWO->value)->where(fn ($q) => $q->whereNull('date_from')->orWhereDate('date_from', '<=', $start))->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', $end))->get(['number_of_days', 'date_from', 'date_to']);
            $windowStart = $allocated->contains(fn ($row) => ! $row->date_from) ? null : $allocated->min('date_from');
            $windowEnd = $allocated->contains(fn ($row) => ! $row->date_to) ? null : $allocated->max('date_to');
            $taken = Leave::query()->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->where('state', '!=', State::REFUSE->value)->when($id, fn ($q) => $q->whereKeyNot($id))->when($windowStart, fn ($q) => $q->whereDate('date_to', '>=', $windowStart))->when($windowEnd, fn ($q) => $q->whereDate('date_from', '<=', $windowEnd))->sum('number_of_days');
            if ($days > $allocated->sum('number_of_days') - $taken) {
                throw ValidationException::withMessages(['holiday_status_id' => 'Insufficient approved allocation covering these dates.']);
            }
        }
    }

    public function decide(User $user, int $id, string $action, string $revision, string $reason): Leave
    {
        $hr = app(NativeHr::class);

        return (new Leave)->getConnection()->transaction(function () use ($user, $id, $action, $revision, $reason, $hr): Leave {
            $current = $hr->record($user, 'leave', $id);
            Employee::query()->whereKey($current->employee_id)->lockForUpdate()->firstOrFail();
            $leave = $hr->record($user, 'leave', $id, true);
            abort_unless(hash_equals($hr->revision($leave), $revision), 409, 'This leave request changed. Reopen it before deciding.');
            abort_unless(in_array($action, $this->actions($user, $leave), true), 403);
            if ($action === 'approve') {
                $values = $leave->only(array_column($this->fields(), 0));
                $values['request_date_from'] = substr((string) $leave->getRawOriginal('request_date_from'), 0, 10);
                $values['request_date_to'] = substr((string) $leave->getRawOriginal('request_date_to'), 0, 10);
                $this->validateRequest($leave->employee, $leave->holidayStatus, $values, $leave->id);
            }
            $before = $leave->getAttributes();
            $first = $leave->holidayStatus->leave_validation_type->value === 'both' && $leave->state === State::CONFIRM;
            $leave->state = $action === 'refuse' ? State::REFUSE : ($first ? State::VALIDATE_ONE : State::VALIDATE_TWO);
            $leave->{$first ? 'first_approver_id' : 'second_approver_id'} = $user->employee->id;
            $leave->save();
            $leave->refresh();
            $this->audit($user, $leave, $action, ['record' => $before, 'reason' => $reason]);

            return $leave;
        });
    }

    private function audit(User $user, Leave $leave, string $action, ?array $before, ?string $key = null, ?string $hash = null): void
    {
        BusinessEvent::query()->create(['company_id' => $leave->company_id, 'actor_id' => $user->id, 'kind' => 'leave', 'record_id' => $leave->id, 'action' => $action, 'request_id' => $key, 'payload_hash' => $hash, 'changes' => ['before' => $before, 'after' => $leave->getAttributes()]]);
    }
}
