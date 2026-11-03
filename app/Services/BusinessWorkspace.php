<?php

namespace App\Services;

use BackedEnum;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\PaymentResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Accounting\Models\Bill;
use Webkul\Accounting\Models\Invoice;
use Webkul\Accounting\Models\Payment;
use Webkul\Employee\Filament\Resources\DepartmentResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Purchase\Filament\Admin\Clusters\Orders\Resources\PurchaseOrderResource;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource;
use Webkul\TimeOff\Models\Leave;

class BusinessWorkspace
{
    public function definitions(string $module): array
    {
        $finance = [
            'purchase-orders' => ['Purchase orders', 'purchases', PurchaseOrder::class, PurchaseOrderResource::class, 'Confirmed supplier orders, amounts and delivery references.'],
            'invoices'        => ['Customer invoices', 'accounting', Invoice::class, InvoiceResource::class, 'Draft and issued invoices, payment status and outstanding balances.'],
            'bills'           => ['Vendor bills', 'accounting', Bill::class, BillResource::class, 'Supplier invoices and amounts still payable.'],
            'payments'        => ['Customer payments', 'accounting', Payment::class, PaymentResource::class, 'Customer receipts and their original accounting records.'],
        ];
        $people = [
            'employees'   => ['Employee directory', 'employees', Employee::class, EmployeeResource::class, 'Work contacts, job titles, departments and employment status.'],
            'departments' => ['Departments', 'employees', Department::class, DepartmentResource::class, 'Company departments and their reporting structure.'],
            'leave'       => ['Leave requests', 'time-off', Leave::class, TimeOffResource::class, 'Leave dates, duration, status and the existing approval workflow.'],
        ];

        return match ($module) {
            'finance' => $finance, 'people' => $people, default => abort(404)
        };
    }

    public function section(User $user, string $module, string $section): array
    {
        $definition = $this->definitions($module)[$section] ?? abort(404);
        [$title, $plugin, $model, $resource, $description] = $definition;
        $installed = Package::isPluginInstalled($plugin) && Package::getPackagePlugin($plugin)?->is_active && Schema::hasTable((new $model)->getTable());
        $allowed = $installed && $user->can('viewAny', $model);

        $native = $module === 'people' && in_array($section, ['employees', 'departments'], true);

        return [
            'native'     => $native, 'can_create' => $allowed && $user->can('create', $model) && $user->can('view', new $model),
            'key'        => $section, 'title' => $title, 'description' => $description,
            'state'      => ! $installed ? 'not_installed' : ($allowed ? 'available' : 'restricted'),
            'url'        => $allowed && ! $native ? $this->url($resource, 'index') : null,
            'create_url' => $allowed && ! $native && $user->can('create', $model) ? $this->url($resource, 'create') : null,
        ];
    }

    public function catalog(User $user, string $module): array
    {
        abort_unless($user->is_active, 403);
        $sections = [];
        foreach (array_keys($this->definitions($module)) as $key) {
            $sections[] = $this->section($user, $module, $key);
        }

        return ['scope' => 'company', 'sections' => $sections, 'planned' => $module === 'finance' ? [
            ['title' => 'Project budgets & cost control', 'description' => 'Budget versions, commitments, actual cost and budget-versus-actual reports.'],
            ['title' => 'Progress claims & retention', 'description' => 'Verified quantities, claim certificates, retention, variations and payment tracking.'],
            ['title' => 'Expenses & reimbursements', 'description' => 'Receipts, cost categories, project allocation and approval before posting.'],
            ['title' => 'Cash flow & reporting', 'description' => 'Receivables/payables ageing, bank reconciliation and currency-aware project reports.'],
        ] : [
            ['title' => 'Attendance & timesheets', 'description' => 'Daily attendance, site assignments, overtime requests and supervisor approval.'],
            ['title' => 'Payroll & payslips', 'description' => 'Approved earnings, deductions, statutory configuration, payroll review and restricted payslip access.'],
            ['title' => 'Employment documents', 'description' => 'Contracts, permits, expiry reminders, onboarding and offboarding checklists.'],
            ['title' => 'Employee self-service', 'description' => 'Own profile, leave balances, requests, expenses and private payslips.'],
        ]];
    }

    public function records(User $user, string $module, string $section, array $filters): array
    {
        abort_unless($user->is_active, 403);
        $access = $this->section($user, $module, $section);
        abort_unless($access['state'] === 'available', 403, 'This module is not available to your account.');
        [, , $model, $resource] = $this->definitions($module)[$section];
        $fields = match ($section) {
            'employees'       => ['id', 'company_id', 'department_id', 'name', 'job_title', 'work_email', 'is_active'],
            'departments'     => ['id', 'company_id', 'name'],
            'leave'           => ['id', 'company_id', 'employee_id', 'holiday_status_id', 'request_date_from', 'request_date_to', 'number_of_days', 'state'],
            'purchase-orders' => ['id', 'company_id', 'partner_id', 'currency_id', 'user_id', 'creator_id', 'name', 'ordered_at', 'state', 'total_amount'],
            'payments'        => ['id', 'company_id', 'partner_id', 'currency_id', 'name', 'date', 'state', 'amount'],
            default           => ['id', 'company_id', 'partner_id', 'currency_id', 'name', 'invoice_date', 'state', 'payment_state', 'amount_total', 'amount_residual'],
        };
        $relations = match ($section) {
            'employees'   => ['company:id,name', 'department:id,name'],
            'departments' => ['company:id,name'],
            'leave'       => ['company:id,name', 'employee:id,name', 'holidayStatus:id,name'],
            default       => ['company:id,name', 'partner:id,name', 'currency:id,name'],
        };
        $query = $model::query()->select($fields)->with($relations)
            ->whereIn('company_id', $user->allowedCompanies()->select('companies.id'));
        if ($section === 'purchase-orders') {
            $query->whereIn('state', ['purchase', 'done']);
        } elseif ($section === 'invoices' || $section === 'bills') {
            $query->where('move_type', $section === 'invoices' ? 'out_invoice' : 'in_invoice');
        } elseif ($section === 'payments') {
            $query->where('partner_type', 'customer');
        }
        if (filled($filters['search'] ?? null)) {
            if ($section === 'leave') {
                $query->whereHas('employee', fn ($employees) => $employees->whereLike('name', '%'.$filters['search'].'%'));
            } else {
                $query->whereLike('name', '%'.$filters['search'].'%');
            }
        }
        $page = $query->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $filters['page'] ?? 1);
        $rows = $page->getCollection()->filter(fn (Model $row): bool => $user->can('view', $row))
            ->map(function (Model $row) use ($section, $resource): array {
                $company = $row->company?->name ?? '—';
                $status = in_array($section, ['employees', 'departments'], true) ? '' : $this->text($row->state);
                $currency = in_array($section, ['employees', 'departments', 'leave'], true) ? '' : ($row->currency?->name ?? '');
                $cells = match ($section) {
                    'employees'       => [$row->name, $row->job_title, $row->department?->name, $row->work_email, $company, $row->is_active ? 'Active' : 'Inactive'],
                    'departments'     => [$row->name, $company],
                    'leave'           => [$row->employee?->name, $row->holidayStatus?->name, substr((string) $row->getRawOriginal('request_date_from'), 0, 10), substr((string) $row->getRawOriginal('request_date_to'), 0, 10), (string) $row->number_of_days, $status, $company],
                    'purchase-orders' => [$row->name, $row->partner?->name, $company, substr((string) $row->getRawOriginal('ordered_at'), 0, 10), $status, (string) $row->getRawOriginal('total_amount').' '.$currency],
                    'payments'        => [$row->name, $row->partner?->name, $company, substr((string) $row->getRawOriginal('date'), 0, 10), $status, (string) $row->getRawOriginal('amount').' '.$currency],
                    default           => [$row->name, $row->partner?->name, $company, substr((string) $row->getRawOriginal('invoice_date'), 0, 10), $status.' / '.$this->text($row->payment_state), (string) $row->getRawOriginal('amount_total').' '.$currency, (string) $row->getRawOriginal('amount_residual').' '.$currency],
                };

                return ['id' => $row->id, 'cells' => array_map(fn ($cell): string => $cell === null || $cell === '' ? '—' : (string) $cell, $cells), 'native' => in_array($section, ['employees', 'departments'], true), 'url' => in_array($section, ['employees', 'departments'], true) ? null : $this->url($resource, 'view', $row)];
            })->values();
        $columns = match ($section) {
            'employees'       => ['Employee', 'Job title', 'Department', 'Work email', 'Company', 'Status'],
            'departments'     => ['Department', 'Company'],
            'leave'           => ['Employee', 'Leave type', 'From', 'To', 'Days', 'Status', 'Company'],
            'purchase-orders' => ['Reference', 'Vendor', 'Company', 'Order date', 'Status', 'Amount'],
            'payments'        => ['Reference', 'Customer', 'Company', 'Date', 'Status', 'Amount'],
            default           => ['Reference', $section === 'bills' ? 'Vendor' : 'Customer', 'Company', 'Date', 'Status', 'Total', 'Outstanding'],
        };

        return ['columns' => $columns, 'rows' => $rows, 'page' => $page->currentPage(), 'has_next' => $page->hasMorePages()];
    }

    private function url(string $resource, string $page, ?Model $record = null): ?string
    {
        $panel = Filament::getPanel('admin');
        if (! Route::has($resource::getRouteBaseName($panel).'.'.$page)) {
            return null;
        }

        return $resource::getUrl($page, $record ? ['record' => $record] : [], panel: 'admin');
    }

    private function text(mixed $value): string
    {
        return $value instanceof BackedEnum ? str_replace('_', ' ', $value->value) : (string) ($value ?? '—');
    }
}
