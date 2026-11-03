<?php

namespace App\Services;

use App\Models\BusinessEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Enums\Gender;
use Webkul\Employee\Enums\MaritalStatus;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

class NativeHr
{
    public function __construct(private BusinessWorkspace $workspace) {}

    public function fields(string $kind): array
    {
        $base = [
            ['name', $kind === 'employees' ? 'Employee name' : 'Department name', 'text', 'Work information', true],
            ['company_id', 'Company', 'select', 'Organisation', true, 'companies'],
        ];
        if ($kind === 'departments') {
            return [...$base,
                ['parent_id', 'Parent department', 'select', 'Organisation', false, 'departments'],
                ['manager_id', 'Department manager', 'select', 'Organisation', false, 'employees'],
            ];
        }
        abort_unless($kind === 'employees', 404);

        return [...$base,
            ['job_title', 'Job title', 'text', 'Work information'],
            ['work_email', 'Work email', 'email', 'Work information'],
            ['work_phone', 'Work phone', 'tel', 'Work information'],
            ['mobile_phone', 'Work mobile', 'tel', 'Work information'],
            ['department_id', 'Department', 'select', 'Organisation', false, 'departments'],
            ['job_id', 'Job position', 'select', 'Organisation', false, 'jobs'],
            ['work_location_id', 'Work location', 'select', 'Organisation', false, 'locations'],
            ['parent_id', 'Reporting manager', 'select', 'Organisation', false, 'employees'],
            ['coach_id', 'Coach', 'select', 'Organisation', false, 'employees'],
            ['is_active', 'Active employee', 'checkbox', 'Organisation'],
            ['private_email', 'Personal email', 'email', 'Personal information'],
            ['private_phone', 'Personal phone', 'tel', 'Personal information'],
            ['birthday', 'Date of birth', 'date', 'Personal information'],
            ['gender', 'Gender', 'select', 'Personal information', false, 'genders'],
            ['marital', 'Marital status', 'select', 'Personal information', true, 'marital'],
            ['private_street1', 'Address line 1', 'text', 'Personal information'],
            ['private_street2', 'Address line 2', 'text', 'Personal information'],
            ['private_city', 'City', 'text', 'Personal information'],
            ['private_zip', 'Postal code', 'text', 'Personal information'],
            ['emergency_contact', 'Emergency contact', 'text', 'Personal information'],
            ['emergency_phone', 'Emergency phone', 'tel', 'Personal information'],
            ['identification_id', 'Identification number', 'text', 'Employment documents'],
            ['passport_id', 'Passport number', 'text', 'Employment documents'],
            ['permit_no', 'Work permit number', 'text', 'Employment documents'],
            ['visa_no', 'Visa number', 'text', 'Employment documents'],
            ['visa_expire', 'Visa expiry', 'date', 'Employment documents'],
            ['work_permit_expiration_date', 'Work permit expiry', 'date', 'Employment documents'],
        ];
    }

    public function model(string $kind): string
    {
        return match ($kind) {
            'employees' => Employee::class, 'departments' => Department::class, default => abort(404)
        };
    }

    public function available(User $user, string $kind): bool
    {
        return $user->is_active && $this->workspace->section($user, 'people', $kind)['state'] === 'available';
    }

    public function companyIds(User $user): array
    {
        return array_values(array_intersect(array_map('intval', $user->allowedCompanies()->pluck('companies.id')->all()), array_map('intval', app(CompanyContext::class)->activeIds())));
    }

    public function query(User $user, string $kind): Builder
    {
        return $this->model($kind)::query()->whereIn('company_id', $this->companyIds($user));
    }

    public function record(User $user, string $kind, int $id, bool $lock = false): Model
    {
        abort_unless($this->available($user, $kind), 403);
        $record = $this->query($user, $kind)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        Gate::forUser($user)->authorize('view', $record);

        return $record;
    }

    public function form(User $user, string $kind): array
    {
        abort_unless($this->available($user, $kind), 403);
        $model = $this->model($kind);
        Gate::forUser($user)->authorize('view', new $model);
        $ids = $this->companyIds($user);
        $option = fn (Model $row): array => ['id' => $row->id, 'name' => $row->name, 'company_id' => $row->company_id];
        $lookup = fn (string $class) => $class::query()->where(fn ($query) => $query->whereIn('company_id', $ids)->orWhereNull('company_id'))->orderBy('name')->get(['id', 'name', 'company_id'])->map($option)->all();

        return ['defaults' => ['company_id' => $ids[0] ?? null, ...($kind === 'employees' ? ['is_active' => true, 'marital' => MaritalStatus::Single->value] : [])], 'fields' => array_map(fn (array $field): array => ['key' => $field[0], 'label' => $field[1], 'type' => $field[2], 'group' => $field[3], 'required' => $field[4] ?? false, 'options' => $field[5] ?? null], $this->fields($kind)),
            'lookups'      => [
                'companies'   => $user->allowedCompanies()->whereKey($ids)->get(['companies.id', 'companies.name'])->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name])->all(),
                'departments' => $lookup(Department::class),
                'employees'   => $user->can('viewAny', Employee::class) ? $this->query($user, 'employees')->orderBy('name')->get(['id', 'name', 'company_id'])->filter(fn (Employee $employee): bool => $user->can('view', $employee))->map($option)->values()->all() : [],
                'jobs'        => $kind === 'employees' ? $lookup(EmployeeJobPosition::class) : [],
                'locations'   => $kind === 'employees' ? $lookup(WorkLocation::class) : [],
                'genders'     => collect(Gender::options())->map(fn ($name, $id): array => ['id' => $id, 'name' => $name])->values()->all(),
                'marital'     => collect(MaritalStatus::options())->map(fn ($name, $id): array => ['id' => $id, 'name' => $name])->values()->all(),
            ], 'can_create' => $user->can('create', $model)];
    }

    public function detail(User $user, string $kind, Model $record): array
    {
        Gate::forUser($user)->authorize('view', $record);
        $values = $record->only(array_column($this->fields($kind), 0));
        foreach ($this->fields($kind) as $field) {
            if ($field[2] === 'date' && filled($values[$field[0]] ?? null)) {
                $values[$field[0]] = substr((string) $values[$field[0]], 0, 10);
            }
        }

        return ['id' => $record->id, 'values' => $values, 'revision' => $this->revision($record), 'can_edit' => $user->can('update', $record)];
    }

    public function save(User $user, string $kind, array $data, ?int $id = null): Model
    {
        $class = $this->model($kind);

        return (new $class)->getConnection()->transaction(function () use ($user, $kind, $data, $id): Model {
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $this->available($user, $kind), 403);
            abort_unless(in_array((int) $data['company_id'], $this->companyIds($user), true), 403);
            Company::query()->whereKey($data['company_id'])->lockForUpdate()->firstOrFail();
            $requestId = $data['request_id'] ?? null;
            $revision = $data['revision'] ?? null;
            unset($data['request_id'], $data['revision']);
            ksort($data);
            $hash = hash('sha256', json_encode(['kind' => $kind, 'values' => $data], JSON_THROW_ON_ERROR));
            if (! $id && $existing = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $requestId)->first()) {
                abort_unless(hash_equals($existing->payload_hash, $hash) && $existing->kind === $kind, 409, 'Submission key already used for different values.');

                return $this->record($user, $kind, $existing->record_id);
            }
            $model = $this->model($kind);
            $record = $id ? $this->record($user, $kind, $id, true) : new $model;
            Gate::forUser($user)->authorize('view', $record);
            Gate::forUser($user)->authorize($id ? 'update' : 'create', $id ? $record : $model);
            if ($id) {
                abort_unless(hash_equals($this->revision($record), (string) $revision), 409, 'This profile changed. Close the form and reopen it to review the latest values.');
                if ((int) $data['company_id'] !== (int) $record->company_id) {
                    throw ValidationException::withMessages(['company_id' => 'Company transfers require a separate HR review.']);
                }
            }
            $this->validateReferences($user, $kind, $data, $id);
            $before = $id ? $record->only(array_keys($data)) : null;
            $record->fill($data);
            if (! $id) {
                $record->creator_id = $user->id;
            }
            $record->save();
            $record->refresh();
            BusinessEvent::query()->create(['company_id' => $record->company_id, 'actor_id' => $user->id, 'kind' => $kind, 'record_id' => $record->id, 'action' => $id ? 'updated' : 'created', 'request_id' => $id ? null : $requestId, 'payload_hash' => $id ? null : $hash, 'changes' => ['before' => $before, 'after' => $record->only(array_keys($data))]]);

            return $record;
        });
    }

    private function validateReferences(User $user, string $kind, array $data, ?int $id): void
    {
        $company = (int) $data['company_id'];
        abort_unless(in_array($company, $this->companyIds($user), true), 403);
        $relations = $kind === 'employees' ? ['department_id' => Department::class, 'job_id' => EmployeeJobPosition::class, 'work_location_id' => WorkLocation::class, 'parent_id' => Employee::class, 'coach_id' => Employee::class] : ['parent_id' => Department::class, 'manager_id' => Employee::class];
        foreach ($relations as $field => $class) {
            if (! filled($data[$field] ?? null)) {
                continue;
            }
            $target = $class::query()->whereKey($data[$field])->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))->first();
            if (! $target || ($class === $this->model($kind) && $id === (int) $target->id)) {
                throw ValidationException::withMessages([$field => 'Choose a valid record in this company.']);
            }
        }
        $parent = $data['parent_id'] ?? null;
        $seen = $id ? [$id] : [];
        while ($parent) {
            if (in_array((int) $parent, $seen, true)) {
                throw ValidationException::withMessages(['parent_id' => 'A reporting hierarchy cannot contain a cycle.']);
            }
            $seen[] = (int) $parent;
            if (count($seen) > 500) {
                throw ValidationException::withMessages(['parent_id' => 'The reporting hierarchy is too deep.']);
            }
            $parent = $this->model($kind)::query()->where(fn ($query) => $query->where('company_id', $company)->orWhereNull('company_id'))->find($parent)?->parent_id;
        }
    }

    private function revision(Model $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
