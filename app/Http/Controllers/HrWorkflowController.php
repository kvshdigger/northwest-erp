<?php

namespace App\Http\Controllers;

use App\Http\Requests\AutomaticPayrollRequest;
use App\Http\Requests\HrWorkflowRequest;
use App\Http\Requests\SelfLeaveRequest;
use App\Http\Requests\SelfProfileRequest;
use App\Models\BusinessEvent;
use App\Services\AutomaticPayroll;
use App\Services\HrWorkflows;
use App\Services\NativeHr;
use App\Services\NativeLeave;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class HrWorkflowController extends Controller
{
    public function __construct(private HrWorkflows $hr) {}

    public function form(Request $request, string $kind): JsonResponse
    {
        $form = $this->hr->form($request->user(), $kind, $request->boolean('self'));
        if ($kind === 'compensation' && $request->integer('employee_id')) {
            abort_unless($this->hr->manager($request->user(), 'payroll'), 403);
            $employee = Employee::query()->whereIn('company_id', app(NativeHr::class)->companyIds($request->user()))->findOrFail($request->integer('employee_id'));
            $form['defaults'] = [...$form['defaults'], 'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'date_of_birth' => $employee->birthday ? substr((string) $employee->birthday, 0, 10) : null];
        }

        return response()->json($form)->header('Cache-Control', 'no-store');
    }

    public function automaticPreview(AutomaticPayrollRequest $request): JsonResponse
    {
        return response()->json(app(AutomaticPayroll::class)->preview($request->user(), $request->validated()))->header('Cache-Control', 'no-store');
    }

    public function automaticStore(AutomaticPayrollRequest $request): JsonResponse
    {
        try {
            $record = app(AutomaticPayroll::class)->save($request->user(), $request->validated());

            return response()->json($this->hr->detail($request->user(), $record), 201)->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            return $this->databaseError($error);
        }
    }

    public function index(HrWorkflowRequest $request, string $kind): JsonResponse
    {
        return response()->json($this->hr->listing($request->user(), $kind, $request->boolean('self'), max(1, min(100000, (int) $request->query('page', 1))), $request->query('expiry', 'all'), $request->integer('employee_id') ?: null))->header('Cache-Control', 'no-store');
    }

    public function store(HrWorkflowRequest $request, string $kind): JsonResponse
    {
        try {
            $record = $this->hr->create($request->user(), $kind, $request->validated('values'), $request->validated('request_id'), $request->file('attachment'));

            return response()->json($this->hr->detail($request->user(), $record), 201)->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            return $this->databaseError($error);
        }
    }

    public function action(HrWorkflowRequest $request, string $kind, int $record): JsonResponse
    {
        try {
            $saved = $this->hr->act($request->user(), $kind, $record, $request->validated());

            return response()->json($this->hr->detail($request->user(), $saved))->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            return $this->databaseError($error);
        }
    }

    public function download(Request $request, string $kind, int $record): mixed
    {
        $row = $this->hr->record($request->user(), $kind, $record);
        $data = $row->data;
        abort_unless(isset($data['file_path']) && Storage::disk('local')->exists($data['file_path']), 404);

        return Storage::disk('local')->download($data['file_path'], 'hr-document-'.$row->id.'.'.pathinfo($data['file_path'], PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function payslip(Request $request, int $record): mixed
    {
        $row = $this->hr->record($request->user(), 'payroll', $record);
        abort_unless($row->state === 'approved', 409, 'Only approved payroll has an issued payslip.');
        $data = $row->data;
        $money = fn ($value) => e(number_format($value / (10 ** $data['precision']), $data['precision'], '.', '').' '.$data['currency']);
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Payslip '.$row->id.'</title><style>body{font:16px system-ui;max-width:800px;margin:40px auto;padding:20px;color:#171b2d}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #ddd}h1{font-size:28px}</style></head><body><h1>'.e($data['company_name']).'</h1><h2>Payslip #'.$row->id.'</h2><p>'.e($data['employee_name']).'</p><p>Period: '.e($data['period_from']).' – '.e($data['period_to']).' · Pay date: '.e($data['pay_date']).'</p><table><tr><th>Component</th><th>Category</th><th>Amount</th></tr><tr><td>Base pay</td><td>Earning</td><td>'.$money($data['base_minor']).'</td></tr>';
        foreach ($data['lines'] as $line) {
            $html .= '<tr><td>'.e($line['label']).'</td><td>'.e($line['category']).'</td><td>'.$money($line['amount_minor']).'</td></tr>';
        }
        $html .= '</table><p>Gross: '.$money($data['gross_minor']).' · Deductions: '.$money($data['deductions_minor']).'</p><h2>Net pay: '.$money($data['net_minor']).'</h2><p>Employer contributions: '.$money($data['employer_minor']).'</p><p>Approved payroll statement. This statement does not confirm bank payment or statutory remittance. Use your browser’s Print / Save as PDF.</p></body></html>';

        return response($html)->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'")->header('X-Content-Type-Options', 'nosniff');
    }

    public function selfService(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_active && $this->hr->enabled(), 403);
        $employee = $this->hr->ownEmployee($request->user()) ?? abort(403, 'Ask HR to link your account to an active employee profile.');
        $leaveEnabled = Package::isPluginInstalled('time-off') && Package::getPackagePlugin('time-off')?->is_active;
        $balances = $requests = $types = [];
        if ($leaveEnabled) {
            $types = LeaveType::query()->where(fn ($q) => $q->where('company_id', $employee->company_id)->orWhereNull('company_id'))->where('is_active', true)->get();
            foreach ($types as $type) {
                $allocations = LeaveAllocation::query()->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->where('state', State::VALIDATE_TWO->value)->where(fn ($q) => $q->whereNull('date_from')->orWhereDate('date_from', '<=', today()))->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', today()))->get();
                $start = $allocations->contains(fn ($row) => ! $row->date_from) ? null : $allocations->min('date_from');
                $end = $allocations->contains(fn ($row) => ! $row->date_to) ? null : $allocations->max('date_to');
                $taken = Leave::query()->where('employee_id', $employee->id)->where('holiday_status_id', $type->id)->where('state', '!=', State::REFUSE->value)->when($start, fn ($q) => $q->whereDate('date_to', '>=', $start))->when($end, fn ($q) => $q->whereDate('date_from', '<=', $end))->sum('number_of_days');
                $balances[] = ['name' => $type->name, 'requires_allocation' => $type->requires_allocation === 'yes', 'approved_allocation' => (string) $allocations->sum('number_of_days'), 'reserved_including_approved' => (string) $taken, 'remaining' => (string) ($allocations->sum('number_of_days') - $taken)];
            }
            $requests = Leave::query()->where('employee_id', $employee->id)->with('holidayStatus:id,name')->latest('id')->limit(20)->get()->map(fn ($row) => ['id' => $row->id, 'type' => $row->holidayStatus?->name, 'from' => substr((string) $row->getRawOriginal('request_date_from'), 0, 10), 'to' => substr((string) $row->getRawOriginal('request_date_to'), 0, 10), 'days' => $row->number_of_days, 'state' => $row->state?->getLabel()])->all();
            $types = $types->map(fn ($type) => $type->only(['id', 'name']))->all();
        }

        return response()->json(['revision' => app(NativeHr::class)->revision($employee), 'profile' => $employee->only(['name', 'job_title', 'work_email', 'work_phone', 'mobile_phone', 'private_email', 'private_phone', 'emergency_contact', 'emergency_phone']), 'leave_enabled' => (bool) $leaveEnabled, 'balances' => $balances, 'requests' => $requests, 'leave_types' => $types])->header('Cache-Control', 'no-store');
    }

    public function selfLeave(SelfLeaveRequest $request): JsonResponse
    {
        $employee = $this->hr->ownEmployee($request->user()) ?? abort(403);
        try {
            $leave = app(NativeLeave::class)->save($request->user(), [...$request->validated('values'), 'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'request_id' => $request->validated('request_id')], null, true);

            return response()->json(['id' => $leave->id, 'status' => $leave->state?->getLabel()], 201)->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            return $this->databaseError($error);
        }
    }

    public function selfProfile(SelfProfileRequest $request): JsonResponse
    {
        $employee = $this->hr->ownEmployee($request->user()) ?? abort(403);
        try {
            $employee->getConnection()->transaction(function () use ($request, $employee): void {
                $record = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals(app(NativeHr::class)->revision($record), $request->validated('revision')), 409, 'Your profile changed. Reload it before saving.');
                $before = $record->only(array_keys($request->validated('values')));
                $record->fill($request->validated('values'));
                $record->save();
                BusinessEvent::query()->create(['company_id' => $record->company_id, 'actor_id' => $request->user()->id, 'kind' => 'hr-profile', 'record_id' => $record->id, 'action' => 'updated', 'changes' => ['before' => $before, 'after' => $record->only(array_keys($before))]]);
            });
        } catch (QueryException $error) {
            return $this->databaseError($error);
        }

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store');
    }

    private function databaseError(QueryException $error): JsonResponse
    {
        Log::error('HR workflow persistence failed', ['sql_state' => $error->errorInfo[0] ?? $error->getCode()]);

        return response()->json(['message' => 'Unable to save this record. Keep your form open and retry unchanged values.'], 500)->header('Cache-Control', 'no-store');
    }
}
