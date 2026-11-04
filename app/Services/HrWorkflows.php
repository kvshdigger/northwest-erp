<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\HrRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Project\Policies\ProgressAccess;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class HrWorkflows
{
    public const TITLES = ['attendance' => 'Attendance & timesheets', 'payroll' => 'Payroll & payslips', 'documents' => 'Employment documents', 'self-service' => 'Employee self-service', 'expenses' => 'Expenses', 'checklists' => 'Onboarding & offboarding', 'payroll-rules' => 'Payroll configuration'];

    public function enabled(): bool
    {
        return Schema::hasTable('v2_hr_records') && Package::isPluginInstalled('employees') && (bool) Package::getPackagePlugin('employees')?->is_active;
    }

    public function manager(User $user, string $kind, bool $review = false): bool
    {
        $group = match ($kind) {
            'payroll', 'payroll-rules' => 'payroll', 'checklists', 'documents' => 'documents', default => $kind
        };

        return $user->is_active && $user->can('v2_hr_'.($review ? 'approve_' : 'manage_').$group);
    }

    public function ownEmployee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->where('is_active', true)->whereIn('company_id', app(NativeHr::class)->companyIds($user))->first();
    }

    public function catalog(User $user): array
    {
        return collect(['attendance', 'payroll', 'documents', 'self-service'])->map(fn ($kind) => ['key' => $kind, 'title' => self::TITLES[$kind], 'description' => 'Employee workflows with company permissions and private self-service access.', 'workflow' => true, 'state' => ! $this->enabled() ? 'not_installed' : ($this->manager($user, $kind) || $this->ownEmployee($user) ? 'available' : 'restricted')])->all();
    }

    public function query(User $user, string $kind, bool $self = false): Builder
    {
        abort_unless($user->is_active && $this->enabled(), 403);
        abort_unless(array_key_exists($kind, self::TITLES) && $kind !== 'self-service', 404);
        $query = HrRecord::query()->where('kind', $kind)->whereIn('company_id', app(NativeHr::class)->companyIds($user));
        if ($self || ! $this->manager($user, $kind)) {
            abort_if(in_array($kind, ['checklists', 'payroll-rules'], true), 403);
            $employee = $this->ownEmployee($user) ?? abort(403, 'An active linked employee profile is required.');
            $query->where('employee_id', $employee->id);
            if ($kind === 'payroll') {
                $query->where('state', 'approved');
            }
        }

        return $query;
    }

    public function record(User $user, string $kind, int $id, bool $lock = false): HrRecord
    {
        return $this->query($user, $kind)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function fields(string $kind): array
    {
        $field = fn ($key, $label, $type = 'text', $required = true) => compact('key', 'label', 'type', 'required');
        $base = [$field('company_id', 'Company', 'select')];
        if ($kind !== 'payroll-rules') {
            $base[] = $field('employee_id', 'Employee', 'select');
        }

        return [...$base, ...match ($kind) {
            'attendance'    => [$field('project_id', 'Site / project', 'select'), $field('starts_at', 'Clock in', 'datetime-local'), $field('ends_at', 'Clock out', 'datetime-local'), $field('timezone', 'Time zone (IANA)'), $field('break_minutes', 'Unpaid break (minutes)', 'number'), $field('overtime_minutes', 'Requested overtime (minutes)', 'number'), $field('notes', 'Work performed / notes')],
            'documents'     => [$field('title', 'Document title'), $field('category', 'Category (contract, permit, visa, other)'), $field('expires_on', 'Expiry / review date', 'date'), $field('notes', 'Notes', 'text', false)],
            'checklists'    => [$field('title', 'Checklist title'), $field('process', 'Process: onboarding or offboarding'), $field('due_on', 'Due date', 'date'), $field('items', 'Checklist items', 'checklist')],
            'expenses'      => [$field('title', 'Expense description'), $field('date', 'Expense date', 'date'), $field('amount', 'Amount in company currency', 'number'), $field('project_id', 'Project', 'select'), $field('notes', 'Business purpose')],
            'payroll-rules' => [$field('title', 'Configuration / version name'), $field('jurisdiction', 'Payroll country profile'), $field('effective_from', 'Effective from', 'date'), $field('effective_to', 'Effective to', 'date'), $field('components', 'Earnings, deductions and employer contributions', 'components')],
            'payroll'       => [$field('rules_id', 'Approved payroll configuration', 'select'), $field('period_from', 'Period from', 'date'), $field('period_to', 'Period to', 'date'), $field('pay_date', 'Pay date', 'date'), $field('base_pay', 'Base pay in company currency', 'number'), $field('components', 'Verified manual component amounts', 'components', false), $field('statutory_verified', 'I verified the applicable statutory amounts for this employee and period', 'checkbox')],
            default         => abort(404),
        }];
    }

    public function form(User $user, string $kind, bool $self): array
    {
        $this->query($user, $kind, $self);
        $manage = ! $self && $this->manager($user, $kind);
        $own = $this->ownEmployee($user);
        $ids = app(NativeHr::class)->companyIds($user);
        $employees = $manage ? Employee::query()->whereIn('company_id', $ids)->where('is_active', true)->get(['id', 'name', 'company_id'])->toArray() : ($own ? [$own->only(['id', 'name', 'company_id'])] : []);
        $companies = Company::query()->whereKey($manage ? $ids : [$own?->company_id])->get(['id', 'name', 'currency_id'])->toArray();
        $rules = $kind === 'payroll' && $manage ? HrRecord::query()->whereIn('company_id', $ids)->where('kind', 'payroll-rules')->where('state', 'approved')->get()->map(fn ($record) => ['id' => $record->id, 'name' => $record->data['title'], 'company_id' => $record->company_id, 'components' => $record->data['components']])->all() : [];

        return ['fields' => $this->fields($kind), 'can_create' => $manage || ($own && in_array($kind, ['attendance', 'documents', 'expenses'], true)), 'manage' => $manage, 'lookups' => ['company_id' => $companies, 'employee_id' => $employees, 'project_id' => app(ProgressAccess::class)->projects($user)->where('is_active', true)->get(['id', 'name', 'company_id'])->toArray(), 'rules_id' => $rules], 'defaults' => ['company_id' => $own?->company_id ?? ($ids[0] ?? null), 'employee_id' => $own?->id, 'timezone' => config('app.timezone'), 'break_minutes' => 0, 'overtime_minutes' => 0, 'jurisdiction' => 'Malaysia', 'process' => 'onboarding', 'components' => $kind === 'payroll-rules' ? $this->malaysiaComponents() : []]];
    }

    public function malaysiaComponents(): array
    {
        return collect(['EPF employee' => 'deduction', 'SOCSO employee' => 'deduction', 'EIS employee' => 'deduction', 'PCB / MTD' => 'deduction', 'LINDUNG 24 Jam / SKBBK (if applicable)' => 'deduction', 'EPF employer' => 'employer', 'SOCSO employer' => 'employer', 'EIS employer' => 'employer'])->map(fn ($category, $label) => ['label' => $label, 'category' => $category, 'method' => 'manual', 'value' => '0.00'])->values()->all();
    }

    public function listing(User $user, string $kind, bool $self, int $page, string $expiry = 'all'): array
    {
        $rows = $this->query($user, $kind, $self)->when($expiry === 'due', fn ($q) => $q->whereDate('due_on', '<=', today()->addDays(30)))->when($expiry === 'expired', fn ($q) => $q->whereDate('due_on', '<', today()))->with('employee:id,name')->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $page);

        return ['rows' => $rows->map(fn ($row) => $this->detail($user, $row))->all(), 'page' => $rows->currentPage(), 'has_next' => $rows->hasMorePages()];
    }

    public function detail(User $user, HrRecord $record): array
    {
        $data = $record->data;
        unset($data['file_path']);
        $actions = [];
        $review = $this->manager($user, $record->kind, true) && $this->manager($user, $record->kind) && (int) $record->creator_id !== (int) $user->id && (int) $record->employee?->user_id !== (int) $user->id;
        if (in_array($record->state, ['draft', 'submitted'], true) && (int) $record->creator_id === (int) $user->id) {
            $actions[] = 'withdraw';
        }
        if ($record->state === 'submitted' && $review) {
            $actions = ['approve', 'reject'];
        }
        if ($record->state === 'draft' && $this->manager($user, $record->kind)) {
            $actions = [...$actions, 'submit'];
        }
        if ($record->kind === 'checklists' && $this->manager($user, 'checklists') && in_array($record->state, ['open', 'completed'], true)) {
            $actions = ['complete', 'reopen'];
        }
        $expires = $data['expires_on'] ?? null;

        return ['id' => $record->id, 'kind' => $record->kind, 'company_id' => $record->company_id, 'employee' => $record->employee?->name, 'state' => $record->state, 'revision' => $record->revision, 'values' => $data, 'actions' => $actions, 'has_file' => isset($record->data['file_path']), 'expiry_status' => $expires ? ($expires < now()->toDateString() ? 'Expired' : ($expires <= now()->addDays(30)->toDateString() ? 'Due within 30 days' : 'Current')) : null];
    }

    public function create(User $user, string $kind, array $values, string $key, ?UploadedFile $attachment): HrRecord
    {
        $this->query($user, $kind);
        $manager = $this->manager($user, $kind);
        abort_unless($manager || in_array($kind, ['attendance', 'documents', 'expenses'], true), 403);
        ksort($values);
        $hash = hash('sha256', json_encode([$kind, $values, $attachment ? hash_file('sha256', $attachment->getRealPath()) : null], JSON_THROW_ON_ERROR));
        $path = null;
        try {
            return (new HrRecord)->getConnection()->transaction(function () use ($user, $kind, $values, $key, $attachment, $hash, $manager, &$path): HrRecord {
                $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->is_active, 403);
                if ($event = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $key)->first()) {
                    abort_unless($event->kind === 'hr-'.$kind && hash_equals($event->payload_hash, $hash), 409, 'Submission key already used.');

                    return $this->record($user, $kind, $event->record_id);
                }
                abort_unless(in_array((int) $values['company_id'], app(NativeHr::class)->companyIds($user), true), 403);
                $company = Company::query()->with('currency')->whereKey($values['company_id'])->lockForUpdate()->firstOrFail();
                $employee = $kind === 'payroll-rules' ? null : Employee::query()->where('company_id', $company->id)->where('is_active', true)->lockForUpdate()->findOrFail($values['employee_id']);
                abort_unless($manager || ((int) $employee?->user_id === (int) $user->id), 403);
                $data = $values;
                $starts = $ends = null;
                if (in_array($kind, ['attendance', 'expenses'], true)) {
                    abort_unless(app(ProgressAccess::class)->projects($user)->where('company_id', $company->id)->where('is_active', true)->whereKey($values['project_id'])->exists(), 422, 'Choose an assigned project in this company.');
                }
                if ($kind === 'attendance') {
                    $starts = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $values['starts_at'], $values['timezone'])->startOfMinute()->utc();
                    $ends = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $values['ends_at'], $values['timezone'])->startOfMinute()->utc();
                    $minutes = (int) $starts->diffInMinutes($ends, false) - (int) $values['break_minutes'];
                    $this->require($ends <= now()->addMinutes(5) && $ends > $starts && $starts->diffInHours($ends) <= 24 && $minutes > 0 && $values['overtime_minutes'] <= $minutes, 'ends_at', 'Choose a completed positive shift of at most 24 hours; breaks and overtime must fit within worked time.');
                    $this->require(! $this->overlaps($employee->id, $kind, $starts, $ends), 'starts_at', 'This employee already has attendance during that shift.');
                    $data['worked_minutes'] = $minutes;
                    $data['regular_minutes'] = $minutes - (int) $values['overtime_minutes'];
                    $data['project_name'] = app(ProgressAccess::class)->projects($user)->findOrFail($values['project_id'])->name;
                }
                if (in_array($kind, ['documents', 'expenses'], true)) {
                    $this->require((bool) $attachment, 'attachment', 'Attach the document or expense receipt.');
                }
                if ($kind === 'expenses') {
                    $this->require((bool) $company->currency && $company->currency->decimal_places <= 4, 'company_id', 'Configure a valid company currency.');
                    $data['amount_minor'] = $this->minor($values['amount'], (int) $company->currency->decimal_places);
                    $this->require($data['amount_minor'] > 0, 'amount', 'Enter a positive expense amount.');
                }
                if (in_array($kind, ['expenses', 'payroll', 'payroll-rules'], true)) {
                    $this->require((bool) $company->currency && $company->currency->decimal_places <= 4, 'company_id', 'Configure a company currency with up to four decimal places.');
                    $data['currency'] = $company->currency->name;
                    $data['precision'] = (int) $company->currency->decimal_places;
                }
                if ($kind === 'payroll-rules') {
                    $this->require($values['effective_to'] >= $values['effective_from'], 'effective_to', 'Effective end must follow the start.');
                    foreach ($data['components'] as $component) {
                        $this->require($component['method'] !== 'percent_base' || (float) $component['value'] <= 1000, 'components', 'Percentage components cannot exceed 1000%.');
                    }
                }
                if ($kind === 'payroll') {
                    $starts = CarbonImmutable::parse($values['period_from'])->startOfDay();
                    $ends = CarbonImmutable::parse($values['period_to'])->endOfDay();
                    $this->require($ends >= $starts && $starts->diffInDays($ends) <= 62, 'period_to', 'Payroll periods must be ordered and at most 62 days.');
                    $this->require(! $this->overlaps($employee->id, $kind, $starts, $ends), 'period_from', 'A payroll record already covers this period.');
                    $rules = HrRecord::query()->where('kind', 'payroll-rules')->where('state', 'approved')->where('company_id', $company->id)->findOrFail($values['rules_id']);
                    $this->require($rules->data['effective_from'] <= $values['period_from'] && $rules->data['effective_to'] >= $values['period_to'], 'rules_id', 'Choose approved rules covering the entire payroll period.');
                    $this->require($rules->data['currency'] === $data['currency'] && $rules->data['precision'] === $data['precision'], 'rules_id', 'Currency changed. Approve a new payroll configuration.');
                    $data = [...$data, ...$this->calculatePayroll($values, $rules->data, $data['precision'])];
                    $data['rules_snapshot'] = $rules->data;
                    $data['employee_name'] = $employee->name;
                    $data['company_name'] = $company->name;
                }
                if ($kind === 'checklists') {
                    $data['completed'] = [];
                }
                if ($attachment) {
                    abort_unless(in_array($kind, ['documents', 'expenses'], true), 422, 'Attachments are accepted only for documents and expense receipts.');
                    $path = $attachment->store('v2-hr/'.$company->id, 'local');
                    abort_unless($path, 500, 'Unable to store this document.');
                    $data['file_path'] = $path;
                    $data['file_name'] = basename($attachment->getClientOriginalName());
                    $data['file_sha256'] = hash_file('sha256', $attachment->getRealPath());
                }
                $record = HrRecord::query()->create(['company_id' => $company->id, 'employee_id' => $employee?->id, 'kind' => $kind, 'state' => match ($kind) {
                    'payroll' => 'draft', 'checklists' => 'open', default => 'submitted'
                }, 'creator_id' => $user->id, 'due_on' => $data['expires_on'] ?? $data['due_on'] ?? null, 'starts_at' => $starts, 'ends_at' => $ends, 'data' => $data]);
                $this->audit($user, $record, 'created', $key, $hash);

                return $record;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function overlaps(int $employee, string $kind, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return HrRecord::query()->where('employee_id', $employee)->where('kind', $kind)->whereNotIn('state', ['rejected', 'withdrawn'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
    }

    public function calculatePayroll(array $values, array $rules, int $precision): array
    {
        $base = $this->minor($values['base_pay'], $precision);
        $gross = $base;
        $deductions = $employer = 0;
        $lines = [];
        $manual = collect($values['components'] ?? []);
        $expected = collect($rules['components'])->where('method', 'manual')->pluck('label')->sort()->values()->all();
        $this->require($expected === $manual->pluck('label')->sort()->values()->all(), 'components', 'Enter every configured manual contribution exactly once.');
        foreach ($rules['components'] as $rule) {
            $amount = $rule['method'] === 'percent_base' ? intdiv($base * $this->minor($rule['value'], 2) + 5000, 10000) : $this->minor($rule['method'] === 'manual' ? $manual->firstWhere('label', $rule['label'])['value'] : $rule['value'], $precision);
            match ($rule['category']) {
                'earning' => $gross += $amount, 'deduction' => $deductions += $amount, 'employer' => $employer += $amount
            };
            $lines[] = ['label' => $rule['label'], 'category' => $rule['category'], 'amount_minor' => $amount];
        }
        $this->require($gross >= $deductions, 'components', 'Deductions exceed gross pay.');

        return ['base_minor' => $base, 'gross_minor' => $gross, 'deductions_minor' => $deductions, 'employer_minor' => $employer, 'net_minor' => $gross - $deductions, 'lines' => $lines];
    }

    public function minor(string|int|float $value, int $precision): int
    {
        $pattern = $precision === 0 ? '/^\d{1,7}$/' : '/^\d{1,7}(?:\.\d{1,'.$precision.'})?$/';
        $this->require($precision >= 0 && $precision <= 4 && preg_match($pattern, (string) $value), 'amount', 'Enter an amount using the company currency precision.');
        $parts = explode('.', (string) $value);

        return (int) $parts[0] * (10 ** $precision) + (int) str_pad($parts[1] ?? '', $precision, '0');
    }

    public function act(User $user, string $kind, int $id, array $action): HrRecord
    {
        return (new HrRecord)->getConnection()->transaction(function () use ($user, $kind, $id, $action): HrRecord {
            $record = $this->record($user, $kind, $id, true);
            abort_unless($record->revision === (int) $action['revision'], 409, 'This record changed. Reopen it before acting.');
            abort_unless(in_array($action['action'], $this->detail($user, $record)['actions'], true), 403);
            $data = $record->data;
            if (in_array($action['action'], ['complete', 'reopen'], true)) {
                $item = $action['item'] ?? -1;
                $this->require(isset($data['items'][$item]), 'item', 'Choose a checklist item.');
                $completed = array_fill_keys($data['completed'], true);
                if ($action['action'] === 'complete') {
                    $completed[$item] = true;
                } else {
                    unset($completed[$item]);
                }
                $data['completed'] = array_keys($completed);
                $record->state = count($completed) === count($data['items']) ? 'completed' : 'open';
            } else {
                $record->state = match ($action['action']) {
                    'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'
                };
                if (in_array($action['action'], ['approve', 'reject'], true)) {
                    $record->reviewer_id = $user->id;
                }
            }
            $record->data = $data;
            $record->revision++;
            $record->save();
            $this->audit($user, $record, $action['action'], reason: $action['reason']);

            return $record;
        });
    }

    private function audit(User $user, HrRecord $record, string $action, ?string $key = null, ?string $hash = null, ?string $reason = null): void
    {
        BusinessEvent::query()->create(['company_id' => $record->company_id, 'actor_id' => $user->id, 'kind' => 'hr-'.$record->kind, 'record_id' => $record->id, 'action' => $action, 'request_id' => $key, 'payload_hash' => $hash, 'changes' => ['revision' => $record->revision, 'state' => $record->state, 'data' => $record->data, 'reason' => $reason]]);
    }

    private function require(bool|int $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['values.'.$field => $message]);
        }
    }
}
