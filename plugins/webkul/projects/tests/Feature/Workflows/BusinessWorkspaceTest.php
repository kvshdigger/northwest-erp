<?php

use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Purchase\Models\Order;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    foreach (['projects', 'employees', 'accounting', 'purchases', 'time-off'] as $plugin) {
        TestBootstrapHelper::ensurePluginInstalled($plugin);
    }
    config(['project-progress.enabled' => true]);
});

it('protects business modules and does not grant access through project membership', function (): void {
    $this->getJson('/admin/v2/project-progress/api/modules/people')->assertUnauthorized();
    SecurityHelper::authenticateWithPermissions([])->refresh();
    $this->getJson('/admin/v2/project-progress/api/modules/people')->assertOk()->assertJsonPath('scope', 'company')->assertJsonPath('sections.0.state', 'restricted')->assertJsonPath('sections.0.url', null);
    $this->getJson('/admin/v2/project-progress/api/modules/people/employees')->assertForbidden();
    $this->getJson('/admin/v2/project-progress/api/modules/finance/invoices')->assertForbidden();
    $this->getJson('/admin/v2/project-progress/api/modules/unknown')->assertNotFound();
});

it('returns only permitted company employee work information and validates pagination', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_employee_employee', 'view_employee_employee'])->refresh();
    $own = Employee::withoutEvents(fn () => Employee::factory()->create(['user_id' => null, 'departure_reason_id' => null, 'name' => 'Visible Site Engineer', 'company_id' => $user->default_company_id, 'identification_id' => 'PRIVATE-IDENTITY', 'private_email' => 'private@example.test']));
    $other = Company::factory()->create(['partner_id' => Partner::factory()]);
    Employee::withoutEvents(fn () => Employee::factory()->create(['user_id' => null, 'departure_reason_id' => null, 'name' => 'Other Company Person', 'company_id' => $other->id]));
    $url = '/admin/v2/project-progress/api/modules/people/employees';
    $data = $this->getJson($url.'?search=visible')->assertOk()->assertJsonCount(1, 'rows')->json();
    expect($data['rows'][0]['id'])->toBe($own->id)->and(json_encode($data))->not->toContain('PRIVATE-IDENTITY', 'private@example.test', 'Other Company Person');
    $this->getJson($url.'?page=0')->assertUnprocessable();
    $this->getJson($url.'?search='.str_repeat('a', 121))->assertUnprocessable();
});

it('requires row view permission as well as list permission', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_employee_employee'])->refresh();
    Employee::withoutEvents(fn () => Employee::factory()->create(['user_id' => null, 'departure_reason_id' => null, 'company_id' => $user->default_company_id]));
    $this->getJson('/admin/v2/project-progress/api/modules/people/employees')->assertOk()->assertJsonCount(0, 'rows');
});

it('keeps invoice and bill types separate and excludes foreign company amounts', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_accounting_invoice', 'view_accounting_invoice', 'view_any_accounting_bill', 'view_accounting_bill'])->refresh();
    foreach (['out_invoice' => 'INV-VISIBLE', 'in_invoice' => 'BILL-VISIBLE', 'entry' => 'JOURNAL-PRIVATE'] as $type => $name) {
        Move::withoutEvents(fn () => Move::factory()->create(['company_id' => $user->default_company_id, 'move_type' => $type, 'name' => $name, 'amount_total' => '123.4567', 'amount_residual' => '12.3400']));
    }
    Move::withoutEvents(fn () => Move::factory()->create(['company_id' => Company::factory()->create(['partner_id' => Partner::factory()])->id, 'move_type' => 'out_invoice', 'name' => 'FOREIGN-INVOICE']));
    $response = $this->getJson('/admin/v2/project-progress/api/modules/finance/invoices')->assertOk()->assertJsonCount(1, 'rows');
    expect(json_encode($response->json()))->toContain('INV-VISIBLE', '123.4567')->not->toContain('BILL-VISIBLE', 'JOURNAL-PRIVATE', 'FOREIGN-INVOICE');
    $this->getJson('/admin/v2/project-progress/api/modules/finance/bills')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.cells.0', 'BILL-VISIBLE');
});

it('shows purchase orders and editable RFQ drafts inside V2', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_purchase_purchase::order', 'view_purchase_purchase::order'])->refresh();
    foreach (['draft', 'purchase'] as $state) {
        Order::withoutEvents(fn () => Order::factory()->create(['name' => 'ORDER-'.$state, 'state' => $state, 'company_id' => $user->default_company_id, 'creator_id' => $user->id]));
    }
    $this->getJson('/admin/v2/project-progress/api/modules/finance/purchase-orders')->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.cells.0', 'ORDER-purchase');
});

it('disables inactive accounts and keeps the entire workspace behind its feature flag', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([])->refresh();
    $user->forceFill(['is_active' => false])->save();
    $this->getJson('/admin/v2/project-progress/api/modules/finance')->assertForbidden();
    config(['project-progress.enabled' => false]);
    $this->getJson('/admin/v2/project-progress/api/modules/finance')->assertNotFound();
});

it('shows leave dates without private reasons or attachments', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_time_off_time::off', 'view_time_off_time::off'])->refresh();
    $employee = Employee::withoutEvents(fn () => Employee::factory()->create(['user_id' => null, 'departure_reason_id' => null, 'company_id' => $user->default_company_id, 'name' => 'Leave Test Employee']));
    Leave::withoutEvents(fn () => Leave::query()->create([
        'holiday_status_id' => LeaveType::query()->value('id'), 'state' => 'confirm', 'request_date_from' => '2026-11-04', 'request_date_to' => '2026-11-05', 'number_of_days' => '2.0000',
        'employee_id'       => $employee->id, 'company_id' => $user->default_company_id, 'private_name' => 'PRIVATE-REASON', 'notes' => 'PRIVATE-NOTE', 'attachment' => 'private-file.pdf',
    ]));
    $response = $this->getJson('/admin/v2/project-progress/api/modules/people/leave?search=Leave%20Test')->assertOk()->assertJsonCount(1, 'rows');
    expect(json_encode($response->json()))->toContain('Leave Test Employee')->not->toContain('PRIVATE-REASON', 'PRIVATE-NOTE', 'private-file.pdf');
});

it('keeps customer receipts separate from vendor payments', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_accounting_payment', 'view_accounting_payment'])->refresh();
    foreach (['customer', 'supplier'] as $type) {
        Payment::withoutEvents(fn () => Payment::query()->create(['state' => 'draft', 'payment_type' => 'inbound', 'date' => '2026-11-04', 'amount' => '100.1250', 'company_id' => $user->default_company_id, 'partner_type' => $type, 'name' => 'PAY-'.$type]));
    }
    $this->getJson('/admin/v2/project-progress/api/modules/finance/payments')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.cells.0', 'PAY-customer');
});
