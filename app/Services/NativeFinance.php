<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Facades\Account as Accounting;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\Tax;
use Webkul\Accounting\Models\Bill;
use Webkul\Accounting\Models\Invoice;
use Webkul\Accounting\Models\Payment;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Facades\PurchaseOrder as Purchasing;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;

class NativeFinance
{
    public const TITLES = ['purchase-orders' => 'Purchase order', 'invoices' => 'Customer invoice', 'bills' => 'Vendor bill', 'payments' => 'Customer payment', 'items' => 'Item'];

    public function model(string $kind): string
    {
        return match ($kind) {
            'purchase-orders' => PurchaseOrder::class, 'invoices' => Invoice::class, 'bills' => Bill::class, 'payments' => Payment::class, 'items' => Product::class, default => abort(404)
        };
    }

    public function available(User $user, string $kind): bool
    {
        return $user->is_active && app(BusinessWorkspace::class)->section($user, 'finance', $kind)['state'] === 'available';
    }

    public function query(User $user, string $kind): Builder
    {
        $ids = app(NativeHr::class)->companyIds($user);
        $query = $this->model($kind)::query()->where(fn ($q) => $q->whereIn('company_id', $ids)->when($kind === 'items', fn ($shared) => $shared->orWhereNull('company_id')));

        return match ($kind) {
            'invoices' => $query->where('move_type', 'out_invoice'), 'bills' => $query->where('move_type', 'in_invoice'), 'payments' => $query->where('partner_type', 'customer'), default => $query
        };
    }

    public function record(User $user, string $kind, int $id, bool $lock = false): Model
    {
        abort_unless($this->available($user, $kind), 403);
        $row = $this->query($user, $kind)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        Gate::forUser($user)->authorize('view', $row);

        return $row;
    }

    public function form(User $user, string $kind, int $companyId, ?int $orderId = null): array
    {
        abort_unless($this->available($user, $kind), 403);
        $company = app(FinanceDocuments::class)->company($user, $companyId);
        $shared = fn (string $class) => $class::query()->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
        $options = fn ($q, array $fields = []) => $q->orderBy('name')->get(['id', 'name', ...$fields])->toArray();
        $journals = Journal::query()->where('company_id', $companyId)->whereIn('type', $kind === 'payments' ? ['bank', 'cash'] : [$kind === 'bills' ? 'purchase' : 'sale']);
        $lookups = ['companies' => $user->allowedCompanies()->whereKey(app(NativeHr::class)->companyIds($user))->get(['companies.id', 'companies.name'])->toArray(),
            'partners'          => $kind === 'items' ? [] : $options($shared(Partner::class)),
            'currencies'        => $options(Currency::query()), 'items' => $options($shared(Product::class)->where($kind === 'purchase-orders' || $kind === 'bills' ? 'enable_purchase' : 'enable_sales', true), ['price', 'cost', 'uom_id']),
            'taxes'             => $options(Tax::query()->where('company_id', $companyId)->where('is_active', true)->where('type_tax_use', in_array($kind, ['purchase-orders', 'bills'], true) ? 'purchase' : 'sale'), ['amount']),
            'journals'          => $options($journals), 'accounts' => $options(Account::query()->whereHas('companies', fn ($q) => $q->whereKey($companyId))->where('deprecated', false)->whereIn('account_type', $kind === 'bills' ? Account::expenseTypes() : Account::incomeTypes())),
            'methods'           => PaymentMethodLine::query()->whereHas('journal', fn ($q) => $q->where('company_id', $companyId)->whereIn('type', ['bank', 'cash']))->whereHas('paymentMethod', fn ($q) => $q->where('payment_type', 'inbound'))->get(['id', 'name', 'journal_id'])->toArray(),
            'work_orders'       => $kind === 'purchase-orders' ? app(WorkOrderPurchasing::class)->options($user, $companyId, $orderId) : [],
            'uoms'              => $options(UOM::query()), 'categories' => $options(Category::query())];
        $config = app(FinanceDocuments::class)->snapshot($company);
        $class = $this->model($kind);

        return ['title' => self::TITLES[$kind], 'can_create' => $user->can('create', $class) && $user->can('view', new $class), 'lookups' => $lookups, 'defaults' => ['company_id' => $companyId, 'currency_id' => $company->currency_id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'terms' => $config[$kind === 'purchase-orders' ? 'po_terms' : 'invoice_terms'], 'lines' => [], 'type' => 'goods', 'enable_purchase' => true, 'enable_sales' => true]];
    }

    public function listing(User $user, string $kind, array $filters): array
    {
        abort_unless($this->available($user, $kind), 403);
        $query = $this->query($user, $kind);
        if (filled($filters['search'] ?? null)) {
            $query->whereLike('name', '%'.$filters['search'].'%');
        }
        $page = $query->with($kind === 'items' ? ['company'] : ['company', 'partner', 'currency'])->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $filters['page'] ?? 1);
        $rows = $page->getCollection()->filter(fn ($row) => $user->can('view', $row))->map(function ($row) use ($kind): array {
            $po = $kind === 'purchase-orders';
            $cells = $kind === 'items' ? [$row->name, $row->reference, $row->type?->value, $row->company?->name, (string) $row->getRawOriginal('price')] : [$row->name, $row->partner?->name, $row->company?->name, substr((string) $row->getRawOriginal($po ? 'ordered_at' : ($kind === 'payments' ? 'date' : 'invoice_date')), 0, 10), $this->state($row), (string) $row->getRawOriginal($po ? 'total_amount' : ($kind === 'payments' ? 'amount' : 'amount_total')).' '.$row->currency?->name];

            return ['id' => $row->id, 'native' => true, 'url' => null, 'cells' => array_map(fn ($value) => filled($value) ? (string) $value : '—', $cells)];
        })->values();

        return ['columns' => $kind === 'items' ? ['Item', 'Reference', 'Type', 'Company', 'Sale price'] : ['Reference', $kind === 'purchase-orders' || $kind === 'bills' ? 'Vendor' : 'Customer', 'Company', 'Date', 'Status', 'Amount'], 'rows' => $rows, 'page' => $page->currentPage(), 'has_next' => $page->hasMorePages()];
    }

    public function state(Model $record): string
    {
        return $record->state instanceof BackedEnum ? $record->state->value : (string) $record->state;
    }

    public function editable(User $user, string $kind, Model $record): bool
    {
        return $record->company_id && $user->can('update', $record) && ($kind === 'items' || in_array($this->state($record), ['draft', 'sent'], true));
    }

    public function canConfirm(User $user, string $kind, Model $row): bool
    {
        return $kind !== 'items' && ($this->editable($user, $kind, $row) || ($kind === 'purchase-orders' && $this->state($row) === 'to_approve' && $user->can('update', $row) && Purchasing::canUserApprove($user)));
    }

    public function canCancel(User $user, string $kind, Model $row): bool
    {
        return $kind === 'purchase-orders' && $row->company_id && $user->can('update', $row) && in_array($this->state($row), ['draft', 'sent', 'to_approve'], true);
    }

    public function detail(User $user, string $kind, Model $row): array
    {
        $po = $kind === 'purchase-orders';
        $values = ['company_id' => $row->company_id];
        if ($kind === 'items') {
            $values += $row->only(['name', 'reference', 'description', 'price', 'cost', 'category_id', 'uom_id', 'uom_po_id', 'enable_sales', 'enable_purchase']);
            $values['type'] = $row->type?->value;
        } elseif ($kind === 'payments') {
            $values += $row->only(['partner_id', 'currency_id', 'journal_id', 'payment_method_line_id', 'amount']);
            $values['date'] = substr((string) $row->getRawOriginal('date'), 0, 10);
            $values['reference'] = $row->memo;
        } else {
            $values += $row->only(['partner_id', 'currency_id', 'journal_id']);
            $values['date'] = substr((string) $row->getRawOriginal($po ? 'ordered_at' : 'invoice_date'), 0, 10);
            $values['due_date'] = substr((string) $row->getRawOriginal($po ? 'planned_at' : 'invoice_date_due'), 0, 10);
            $values['reference'] = $row->{$po ? 'partner_reference' : 'reference'};
            $values['terms'] = $row->{$po ? 'description' : 'narration'};
            $lines = $po ? $row->lines : $row->invoiceLines;
            $allocations = $po ? WorkOrderAllocation::query()->with('version')->where('order_id', $row->id)->get()->keyBy('order_line_id') : collect();
            if ($po) {
                $values['work_order_id'] = $row->work_order_id;
                $values['work_order_version_id'] = $allocations->first()?->version_id;
            }
            $values['lines'] = $lines->map(fn ($line) => [...($po ? ['work_order_line_key' => $allocations->get($line->id)?->source_key, 'work_order_route' => app(WorkOrderPurchasing::class)->sourceLabel($allocations->get($line->id))] : []), 'item_id' => $line->product_id, 'description' => $line->name, 'quantity' => (string) $line->{$po ? 'product_qty' : 'quantity'}, 'unit_price' => (string) $line->{$po ? 'price_unit' : 'price_unit'}, 'discount' => (string) ($line->discount ?? 0), 'account_id' => $line->account_id, 'tax_ids' => $line->taxes->pluck('id')->all()])->all();
        }

        return ['id' => $row->id, 'kind' => $kind, 'title' => self::TITLES[$kind], 'name' => $row->name, 'state' => $kind === 'items' ? 'Active' : $this->state($row), 'values' => $values, 'work_order' => $po && $row->work_order_id ? ['reference' => WorkOrder::findOrFail($row->work_order_id)->reference, 'versions' => $allocations->pluck('version.number')->unique()->values()->all()] : null, 'revision' => $this->revision($row), 'can_edit' => $this->editable($user, $kind, $row), 'can_confirm' => $this->canConfirm($user, $kind, $row), 'total' => (string) ($row->{$po ? 'total_amount' : ($kind === 'payments' ? 'amount' : 'amount_total')} ?? ''), 'currency' => $row->currency?->name, 'can_cancel' => $this->canCancel($user, $kind, $row), 'can_print' => $kind !== 'items' && $kind !== 'payments'];
    }

    public function revision(Model $row): string
    {
        $lines = method_exists($row, 'lines') ? $row->lines()->with('taxes')->get()->toArray() : [];

        return hash('sha256', json_encode([$row->getRawOriginal(), $lines, $row instanceof Order ? WorkOrderAllocation::where('order_id', $row->id)->get()->toArray() : []], JSON_THROW_ON_ERROR));
    }

    private function validOption(array $options, mixed $id, string $field): void
    {
        if (! collect($options)->contains(fn ($option) => (string) $option['id'] === (string) $id)) {
            throw ValidationException::withMessages([$field => 'Choose an available option for this company.']);
        }
    }

    public function save(User $user, string $kind, array $input, ?int $id = null): Model
    {
        return app(WorkOrderPurchasing::class)->run(fn () => $this->persist($user, $kind, $input, $id));
    }

    private function persist(User $user, string $kind, array $input, ?int $id = null): Model
    {
        abort_unless($this->available($user, $kind), 403);
        $class = $this->model($kind);

        return (new $class)->getConnection()->transaction(function () use ($user, $kind, $input, $id, $class): Model {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->available($user, $kind), 403);
            $company = app(FinanceDocuments::class)->company($user, (int) $input['values']['company_id'], true);
            $hash = hash('sha256', json_encode($input['values']));
            if (! $id && $event = BusinessEvent::query()->where('actor_id', $user->id)->where('request_id', $input['request_id'])->first()) {
                abort_unless($event->kind === 'finance-'.$kind && $event->payload_hash === $hash, 409, 'This submission key was used for different data.');

                return $this->record($user, $kind, $event->record_id);
            }
            $row = $id ? $this->record($user, $kind, $id, true) : new $class;
            if ($id) {
                abort_unless((int) $row->company_id === $company->id, 422, 'A saved record cannot change company.');
                abort_unless($this->editable($user, $kind, $row), 403, 'Only drafts can be edited.');
                abort_unless(hash_equals($this->revision($row), $input['revision']), 409, 'This record changed. Reopen it before saving.');
            } else {
                Gate::forUser($user)->authorize('create', $class);
                Gate::forUser($user)->authorize('view', $row);
            }
            $data = $input['values'];
            $form = $this->form($user, $kind, $company->id, $kind === 'purchase-orders' ? $id : null);
            $workAllocations = $kind === 'purchase-orders' ? app(WorkOrderPurchasing::class)->prepare($user, $data, $id ? $row : null) : [];
            if ($kind === 'items') {
                $this->validOption($form['lookups']['categories'], $data['category_id'], 'values.category_id');
                foreach (['uom_id', 'uom_po_id'] as $field) {
                    $this->validOption($form['lookups']['uoms'], $data[$field], 'values.'.$field);
                }
                abort_unless(UOM::findOrFail($data['uom_id'])->category_id === UOM::findOrFail($data['uom_po_id'])->category_id, 422, 'Purchase and sales units must use the same unit category.');
                $row->fill($data);
            } else {
                $this->validOption($form['lookups']['partners'], $data['partner_id'], 'values.partner_id');
                $this->validOption($form['lookups']['currencies'], $data['currency_id'], 'values.currency_id');
                $po = $kind === 'purchase-orders';
                $values = ['company_id' => $company->id, 'partner_id' => $data['partner_id'], 'currency_id' => $data['currency_id']];
                if ($kind === 'payments') {
                    $this->validOption($form['lookups']['journals'], $data['journal_id'], 'values.journal_id');
                    $this->validOption(array_filter($form['lookups']['methods'], fn ($m) => $m['journal_id'] == $data['journal_id']), $data['payment_method_line_id'], 'values.payment_method_line_id');
                    $values += ['journal_id' => $data['journal_id'], 'payment_method_line_id' => $data['payment_method_line_id'], 'amount' => $data['amount'], 'date' => $data['date'], 'memo' => $data['reference'] ?? null, 'payment_type' => 'inbound', 'partner_type' => 'customer', 'state' => 'draft'];
                } elseif ($po) {
                    $values += ['work_order_id' => $data['work_order_id'] ?? null, 'ordered_at' => $data['date'], 'planned_at' => $data['due_date'] ?? null, 'partner_reference' => $data['reference'] ?? null, 'description' => $data['terms'] ?? null, 'user_id' => $user->id];
                    if (! $id) {
                        $values += ['state' => 'draft', 'creator_id' => $user->id, 'name' => app(FinanceDocuments::class)->next($company, 'po', $data['date'])];
                    }
                } else {
                    $this->validOption($form['lookups']['journals'], $data['journal_id'], 'values.journal_id');
                    $values += ['journal_id' => $data['journal_id'], 'date' => $data['date'], 'invoice_date' => $data['date'], 'invoice_date_due' => $data['due_date'] ?? $data['date'], 'reference' => $data['reference'] ?? null, 'narration' => $data['terms'] ?? null, 'move_type' => $kind === 'invoices' ? 'out_invoice' : 'in_invoice'];
                    if (! $id) {
                        $values += ['state' => 'draft', 'name' => $kind === 'invoices' ? app(FinanceDocuments::class)->next($company, 'invoice', $data['date']) : null];
                    }
                }
                $row->fill($values);
            }
            $row->save();
            if (! in_array($kind, ['items', 'payments'], true)) {
                $po = $kind === 'purchase-orders';
                if ($id) {
                    $existing = $po ? $row->lines : $row->invoiceLines;
                    abort_if($existing->contains(fn ($line) => filled($line->display_type) && ($line->display_type instanceof BackedEnum ? $line->display_type->value : $line->display_type) !== 'product'), 422, 'This document contains section or note lines that require an extended editor. Its existing data has been preserved.');
                    foreach ($existing as $line) {
                        $line->taxes()->detach();
                        $line->delete();
                    }
                }
                foreach ($data['lines'] as $index => $line) {
                    $this->validOption($form['lookups']['items'], $line['item_id'], 'values.lines.'.$index.'.item_id');
                    $item = Product::query()->findOrFail($line['item_id']);
                    foreach ($line['tax_ids'] ?? [] as $tax) {
                        $this->validOption($form['lookups']['taxes'], $tax, 'values.lines.'.$index.'.tax_ids');
                    }
                    $attributes = ['product_id' => $item->id, 'name' => $line['description'], 'price_unit' => $line['unit_price'], 'discount' => $line['discount'] ?? 0, 'company_id' => $company->id, 'currency_id' => $data['currency_id'], 'partner_id' => $data['partner_id'], 'uom_id' => $item->uom_id];
                    if ($po) {
                        $attributes += ['product_qty' => $line['quantity'], 'product_uom_qty' => $line['quantity'], 'planned_at' => $data['due_date'] ?? $data['date']];
                        $saved = $row->lines()->create($attributes);
                    } else {
                        $this->validOption($form['lookups']['accounts'], $line['account_id'], 'values.lines.'.$index.'.account_id');
                        $attributes += ['quantity' => $line['quantity'], 'account_id' => $line['account_id'], 'display_type' => 'product', 'journal_id' => $data['journal_id']];
                        $saved = $row->invoiceLines()->create($attributes);
                    }
                    if ($po && isset($workAllocations[$index])) {
                        WorkOrderAllocation::create([...$workAllocations[$index], 'order_id' => $row->id, 'order_line_id' => $saved->id]);
                    }
                    $saved->taxes()->sync($line['tax_ids'] ?? []);
                }
                $row = $po ? Purchasing::computePurchaseOrder($row->fresh()) : Accounting::computeAccountMove($row->fresh());
            }
            $this->audit($user, $kind, $row, $id ? 'updated' : 'created', ['values' => $this->detail($user, $kind, $row)['values'], 'document' => app(FinanceDocuments::class)->snapshot($company)], $id ? null : $input['request_id'], $hash);

            return $row->fresh();
        });
    }

    public function confirm(User $user, string $kind, int $id, string $revision): Model
    {
        return app(WorkOrderPurchasing::class)->run(fn () => $this->confirmRecord($user, $kind, $id, $revision));
    }

    private function confirmRecord(User $user, string $kind, int $id, string $revision): Model
    {
        return (new BusinessEvent)->getConnection()->transaction(function () use ($user, $kind, $id, $revision): Model {
            $visible = $this->record($user, $kind, $id);
            app(FinanceDocuments::class)->company($user, $visible->company_id, true);
            $row = $this->record($user, $kind, $id, true);
            abort_unless($this->canConfirm($user, $kind, $row), 403);
            abort_unless(hash_equals($this->revision($row), $revision), 409, 'This record changed. Reopen it before confirming.');
            if ($kind === 'purchase-orders') {
                abort_unless($row->lines()->exists(), 422, 'Add at least one item.');
                app(WorkOrderPurchasing::class)->assertExisting($user, $row);
                $row = Purchasing::confirmPurchaseOrder($row);
            } elseif ($kind === 'payments') {
                $row = Accounting::postPayment($row);
                if (! $row->move_id) {
                    $row->generateJournalEntry();
                    $row->refresh();
                }
                Accounting::confirmMove($row->move);
            } else {
                $row = Accounting::confirmMove($row);
            }
            $this->audit($user, $kind, $row, 'confirmed', ['document' => app(FinanceDocuments::class)->snapshot(app(FinanceDocuments::class)->company($user, $row->company_id))]);

            return $row->fresh();
        });
    }

    public function cancel(User $user, int $id, string $revision): Model
    {
        return app(WorkOrderPurchasing::class)->run(fn () => (new BusinessEvent)->getConnection()->transaction(function () use ($user, $id, $revision): Model {
            $visible = $this->record($user, 'purchase-orders', $id);
            app(FinanceDocuments::class)->company($user, $visible->company_id, true);
            $row = $this->record($user, 'purchase-orders', $id, true);
            abort_unless($this->canCancel($user, 'purchase-orders', $row), 403, 'Only unconfirmed POs can be cancelled here.');
            abort_unless(hash_equals($this->revision($row), $revision), 409, 'Reopen the PO before cancelling.');
            if ($row->work_order_id) {
                WorkOrder::whereKey($row->work_order_id)->lockForUpdate()->firstOrFail();
            }
            $row = Purchasing::cancelPurchaseOrder($row);
            $this->audit($user, 'purchase-orders', $row, 'cancelled', ['work_order_id' => $row->work_order_id]);

            return $row->fresh();
        }));
    }

    private function audit(User $user, string $kind, Model $row, string $action, array $changes, ?string $key = null, ?string $hash = null): void
    {
        BusinessEvent::create(['company_id' => $row->company_id, 'actor_id' => $user->id, 'kind' => 'finance-'.$kind, 'record_id' => $row->id, 'action' => $action, 'changes' => $changes, 'request_id' => $key, 'payload_hash' => $hash]);
    }
}
