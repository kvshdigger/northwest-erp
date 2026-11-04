<?php

use App\Models\BusinessEvent;
use App\Models\FinanceConfiguration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Models\Payment;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    foreach (['projects', 'products', 'accounting', 'purchases'] as $plugin) {
        TestBootstrapHelper::ensurePluginInstalled($plugin);
    }
    config(['project-progress.enabled' => true]);
    $permissions = ['v2_finance_configure'];
    foreach (['purchase_purchase::order', 'accounting_invoice', 'accounting_bill', 'accounting_payment', 'product_product'] as $resource) {
        foreach (['view_any', 'view', 'create', 'update'] as $action) {
            $permissions[] = $action.'_'.$resource;
        }
    }
    $this->user = SecurityHelper::authenticateWithPermissions($permissions)->refresh();
    $this->company = $this->user->defaultCompany;
    $this->partner = Partner::factory()->create(['company_id' => $this->company->id]);
    $this->item = Product::factory()->create(['company_id' => $this->company->id, 'type' => 'service', 'enable_purchase' => true, 'enable_sales' => true]);
    $this->base = '/admin/v2/project-progress/api/finance/';
    $this->values = ['company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'currency_id' => $this->company->currency_id, 'date' => '2026-11-04', 'due_date' => '2026-11-30', 'reference' => 'Vendor quote', 'terms' => 'Test terms', 'lines' => [['item_id' => $this->item->id, 'description' => 'Site service', 'quantity' => '2.00', 'unit_price' => '125.50', 'discount' => '10.00', 'tax_ids' => []]]];
});

it('uses native finance actions and item terminology', function (): void {
    $catalog = $this->getJson('/admin/v2/project-progress/api/modules/finance')->assertOk()->json();
    foreach ($catalog['sections'] as $section) {
        expect($section['native'])->toBeTrue()->and($section['url'])->toBeNull()->and($section['create_url'])->toBeNull();
    }
    expect(collect($catalog['sections'])->pluck('title')->all())->toContain('Items', 'PO / Invoice configuration');
    $this->getJson($this->base.'purchase-orders/form')->assertOk()->assertJsonPath('can_create', true);
});

it('creates and edits an existing-model PO draft with server-calculated totals and safe retries', function (): void {
    $payload = ['request_id' => (string) Str::uuid(), 'values' => $this->values];
    $created = $this->postJson($this->base.'purchase-orders', $payload)->assertCreated()->assertJsonPath('state', 'draft')->json();
    expect((float) $created['total'])->toBe(225.9);
    $this->postJson($this->base.'purchase-orders', $payload)->assertCreated()->assertJsonPath('id', $created['id']);
    $this->postJson($this->base.'purchase-orders', [...$payload, 'values' => [...$this->values, 'reference' => 'Changed']])->assertConflict();
    $edit = ['revision' => $created['revision'], 'values' => [...$this->values, 'reference' => 'Updated reference']];
    $this->putJson($this->base.'purchase-orders/'.$created['id'], $edit)->assertOk()->assertJsonPath('values.reference', 'Updated reference');
    $this->putJson($this->base.'purchase-orders/'.$created['id'], $edit)->assertConflict();
    expect(PurchaseOrder::query()->count())->toBe(1)->and(BusinessEvent::where('kind', 'finance-purchase-orders')->count())->toBe(2);
});

it('isolates company partners, items, records and settings', function (): void {
    $foreign = Company::factory()->create(['partner_id' => Partner::factory()]);
    $partner = Partner::factory()->create(['company_id' => $foreign->id]);
    $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => [...$this->values, 'partner_id' => $partner->id]])->assertUnprocessable();
    $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => [...$this->values, 'company_id' => $foreign->id]])->assertForbidden();
    $this->getJson($this->base.'configuration?company_id='.$foreign->id)->assertForbidden();
    $this->getJson($this->base.'invoices/form?company_id='.$foreign->id)->assertForbidden();
    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('requires explicit configuration permission and validates logos', function (): void {
    $data = $this->getJson($this->base.'configuration')->assertOk()->json();
    $settings = collect($data['settings'])->except(['logo_data', 'po_preview', 'invoice_preview'])->all();
    $payload = ['company_id' => $this->company->id, 'revision' => 0, 'settings' => $settings];
    $this->post($this->base.'configuration', [...$payload, 'logo' => UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->user->revokePermissionTo('v2_finance_configure');
    $this->getJson($this->base.'configuration')->assertForbidden();
    $this->postJson($this->base.'configuration', $payload)->assertForbidden();
});

it('saves versioned branding and sequences without consuming preview numbers or renumbering documents', function (): void {
    $config = $this->getJson($this->base.'configuration')->assertOk()->json();
    $settings = collect($config['settings'])->except(['logo_data', 'po_preview', 'invoice_preview'])->all();
    $settings = [...$settings, 'display_name' => 'Northwest Test', 'po_prefix' => 'NW/PO/%(year)/', 'po_padding' => 4, 'po_next' => 42, 'invoice_prefix' => 'NW/INV/', 'invoice_padding' => 4, 'invoice_next' => 7];
    $saved = $this->post($this->base.'configuration', ['company_id' => $this->company->id, 'revision' => 0, 'settings' => $settings, 'logo' => UploadedFile::fake()->image('logo.png', 40, 40)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('settings.po_preview', 'NW/PO/2026/0042')->json();
    $this->getJson($this->base.'configuration')->assertOk()->assertJsonPath('settings.po_next', 42);
    $doc = $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => $this->values])->assertCreated()->assertJsonPath('name', 'NW/PO/2026/0042')->json();
    $this->get($this->base.'purchase-orders/'.$doc['id'].'/print')->assertOk()->assertSee('Northwest Test')->assertSee('data:image/png;base64', false)->assertSee('225.90');
    $settings['display_name'] = 'New branding';
    $settings['po_next'] = 43;
    $this->postJson($this->base.'configuration', ['company_id' => $this->company->id, 'revision' => 1, 'settings' => $settings])->assertOk()->assertJsonPath('revision', 2);
    $this->get($this->base.'purchase-orders/'.$doc['id'].'/print')->assertOk()->assertSee('Northwest Test')->assertDontSee('New branding');
    $this->postJson($this->base.'configuration', ['company_id' => $this->company->id, 'revision' => 1, 'settings' => $settings])->assertConflict();
    $this->postJson($this->base.'configuration', ['company_id' => $this->company->id, 'revision' => 2, 'settings' => [...$settings, 'po_next' => 1]])->assertUnprocessable();
    expect(FinanceConfiguration::first()->getRawOriginal('settings'))->not->toContain('Northwest Test', 'New branding');
});

it('creates customer invoices and vendor bills with existing accounting calculations and separate document types', function (): void {
    foreach (['invoices' => 'sale', 'bills' => 'purchase'] as $kind => $type) {
        $journal = Journal::query()->where('company_id', $this->company->id)->where('type', $type)->firstOrFail();
        $account = Account::query()->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))->whereIn('account_type', $kind === 'bills' ? Account::expenseTypes() : Account::incomeTypes())->firstOrFail();
        $values = [...$this->values, 'journal_id' => $journal->id, 'lines' => [[...$this->values['lines'][0], 'account_id' => $account->id]]];
        $doc = $this->postJson($this->base.$kind, ['request_id' => (string) Str::uuid(), 'values' => $values])->assertCreated()->json();
        expect((float) $doc['total'])->toBe(225.9);
        $this->getJson($this->base.($kind === 'invoices' ? 'bills' : 'invoices').'/'.$doc['id'])->assertNotFound();
        $this->get($this->base.$kind.'/'.$doc['id'].'/print')->assertOk()->assertSee('225.90');
    }
});

it('creates an item using V2 names while retaining the existing item model', function (): void {
    $values = ['company_id' => $this->company->id, 'name' => 'Site Item', 'reference' => 'SITE-01', 'type' => 'service', 'price' => '100.00', 'cost' => '90.00', 'description' => 'Native V2 item', 'category_id' => $this->item->category_id, 'uom_id' => $this->item->uom_id, 'uom_po_id' => $this->item->uom_id, 'enable_sales' => true, 'enable_purchase' => true];
    $item = $this->postJson($this->base.'items', ['request_id' => (string) Str::uuid(), 'values' => $values])->assertCreated()->assertJsonPath('values.name', 'Site Item')->json();
    expect(Product::findOrFail($item['id'])->reference)->toBe('SITE-01');
});

it('rejects client totals and unauthorized writes or print access', function (): void {
    $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => [...$this->values, 'total_amount' => 1]])->assertUnprocessable();
    $doc = $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => $this->values])->assertCreated()->json();
    $this->user->syncPermissions([]);
    $this->getJson($this->base.'purchase-orders/'.$doc['id'])->assertForbidden();
    $this->get($this->base.'purchase-orders/'.$doc['id'].'/print')->assertForbidden();
    $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => $this->values])->assertForbidden();
});

it('posts invoice and bill drafts through the accounting engine and prevents later edits', function (): void {
    foreach (['invoices' => 'sale', 'bills' => 'purchase'] as $kind => $type) {
        $journal = Journal::where('company_id', $this->company->id)->where('type', $type)->firstOrFail();
        $account = Account::whereHas('companies', fn ($q) => $q->whereKey($this->company->id))->whereIn('account_type', $kind === 'bills' ? Account::expenseTypes() : Account::incomeTypes())->firstOrFail();
        $values = [...$this->values, 'journal_id' => $journal->id, 'lines' => [[...$this->values['lines'][0], 'account_id' => $account->id]]];
        $doc = $this->postJson($this->base.$kind, ['request_id' => (string) Str::uuid(), 'values' => $values])->assertCreated()->json();
        $posted = $this->postJson($this->base.$kind.'/'.$doc['id'].'/confirm', ['revision' => $doc['revision']])->assertOk()->assertJsonPath('state', 'posted')->assertJsonPath('can_edit', false)->json();
        $move = Move::findOrFail($doc['id']);
        expect(abs($move->lines->sum('debit') - $move->lines->sum('credit')))->toBeLessThan(0.001);
        $this->putJson($this->base.$kind.'/'.$doc['id'], ['revision' => $posted['revision'], 'values' => $values])->assertForbidden();
        $this->postJson($this->base.$kind.'/'.$doc['id'].'/confirm', ['revision' => $posted['revision']])->assertForbidden();
    }
});

it('records a customer payment with the existing payment and journal models', function (): void {
    $form = $this->getJson($this->base.'payments/form')->assertOk()->json();
    $method = collect($form['lookups']['methods'])->first();
    expect($method)->not->toBeNull();
    $values = ['company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'currency_id' => $this->company->currency_id, 'date' => '2026-11-04', 'reference' => 'Synthetic receipt', 'amount' => '125.50', 'journal_id' => $method['journal_id'], 'payment_method_line_id' => $method['id']];
    $doc = $this->postJson($this->base.'payments', ['request_id' => (string) Str::uuid(), 'values' => $values])->assertCreated()->assertJsonPath('state', 'draft')->json();
    $this->postJson($this->base.'payments/'.$doc['id'].'/confirm', ['revision' => $doc['revision']])->assertOk()->assertJsonPath('can_edit', false);
    $payment = Payment::findOrFail($doc['id']);
    expect($payment->move_id)->not->toBeNull()->and($payment->move->state->value)->toBe('posted');
});

it('confirms PO drafts through purchasing without allowing stale confirmation', function (): void {
    $doc = $this->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => $this->values])->assertCreated()->json();
    $this->postJson($this->base.'purchase-orders/'.$doc['id'].'/confirm', ['revision' => str_repeat('0', 64)])->assertConflict();
    $this->postJson($this->base.'purchase-orders/'.$doc['id'].'/confirm', ['revision' => $doc['revision']])->assertOk();
    expect(PurchaseOrder::findOrFail($doc['id'])->state->value)->toBeIn(['purchase', 'done', 'to approve']);
});

it('rolls back document and sequence changes when the audit record fails', function (): void {
    BusinessEvent::creating(fn () => throw new RuntimeException('Audit unavailable'));
    try {
        $this->withoutExceptionHandling()->postJson($this->base.'purchase-orders', ['request_id' => (string) Str::uuid(), 'values' => $this->values]);
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Audit unavailable');
    } finally {
        BusinessEvent::flushEventListeners();
        (new ReflectionMethod(BusinessEvent::class, 'booted'))->invoke(null);
    }
    expect(PurchaseOrder::count())->toBe(0);
    $this->getJson($this->base.'configuration')->assertOk()->assertJsonPath('settings.po_next', 1);
});
