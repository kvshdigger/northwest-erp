<?php

use App\Models\WorkOrderAllocation;
use App\Models\WorkOrderVersion;
use App\Services\NativeFinance;
use App\Services\WorkOrderPurchasing;
use App\Services\WorkOrders;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Purchase\Models\PurchaseOrder;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    foreach (['projects', 'products', 'accounting', 'purchases'] as $plugin) {
        TestBootstrapHelper::ensurePluginInstalled($plugin);
    }
    config(['project-progress.enabled'=>true]);
    $this->user = SecurityHelper::authenticateWithPermissions(['view_any_purchase_purchase::order', 'view_purchase_purchase::order', 'create_purchase_purchase::order', 'update_purchase_purchase::order', 'view_accounting_invoice'])->refresh();
    $this->project = Project::factory()->create(['user_id'=>$this->user->id, 'company_id'=>$this->user->default_company_id]);
    $this->partner = Partner::factory()->create(['company_id'=>$this->user->default_company_id]);
    $this->item = Product::factory()->create(['company_id'=>$this->user->default_company_id, 'type'=>'service', 'enable_purchase'=>true]);
    $this->base = '/admin/v2/project-progress/api/';
    $route = (string) Str::uuid();
    $this->award = ['project_id'=>$this->project->id, 'partner_id'=>$this->partner->id, 'currency_id'=>$this->user->defaultCompany->currency_id, 'reference'=>'WO-'.Str::random(12), 'title'=>'Main contractor award', 'reason'=>'Received signed Work Order', 'routes'=>[['key'=>$route, 'name'=>'Authorised road', 'chainage_from'=>'0+000', 'chainage_to'=>'1+000']], 'lines'=>[['key'=>(string) Str::uuid(), 'route_key'=>$route, 'item_id'=>$this->item->id, 'description'=>'Trenching', 'quantity'=>'1000', 'maincon_rate'=>'15', 'subcon_rate'=>'10']]];
});

function createWorkAward($test, bool $approve = true): array
{
    $wo = $test->postJson($test->base.'work-orders', ['request_id'=>(string) Str::uuid(), 'values'=>$test->award])->assertCreated()->json();
    if (! $approve) {
        return $wo;
    }
    $v = $wo['versions'][0];

    return $test->postJson($test->base.'work-orders/'.$wo['id'].'/versions/'.$v['id'].'/approve', ['revision'=>$v['revision']])->assertOk()->json();
}
function awardPurchase($test, array $wo, string $qty = '600', string $rate = '10'): array
{
    return ['request_id'=>(string) Str::uuid(), 'values'=>['company_id'=>$wo['company_id'], 'partner_id'=>$test->partner->id, 'currency_id'=>$wo['currency_id'], 'work_order_id'=>$wo['id'], 'work_order_version_id'=>$wo['current_version_id'], 'date'=>'2026-11-04', 'due_date'=>'2026-11-30', 'reference'=>'Subcontract', 'terms'=>'Test terms', 'lines'=>[['work_order_line_key'=>$test->award['lines'][0]['key'], 'item_id'=>$test->item->id, 'description'=>'Trenching', 'quantity'=>$qty, 'unit_price'=>$rate, 'discount'=>'0', 'tax_ids'=>[]]]]];
}

it('only exposes approved work order routes and validates their chainages', function (): void {
    $this->getJson($this->base.'work-orders/form')->assertOk()->assertJsonPath('projects.0.can_manage', true);
    ProgressRoute::factory()->create(['project_id'=>$this->project->id, 'name'=>'Unawarded legacy road']);
    $wo = createWorkAward($this, false);
    $this->getJson($this->base.'projects/'.$this->project->id.'/workspace')->assertOk()->assertJsonCount(0, 'routes');
    $v = $wo['versions'][0];
    $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$v['id'].'/approve', ['revision'=>1])->assertOk();
    $routes = $this->getJson($this->base.'projects/'.$this->project->id.'/workspace')->assertOk()->assertJsonCount(1, 'routes')->json('routes');
    expect($routes[0]['name'])->toBe('Authorised road')->and($routes[0]['chainage_to'])->toBe('1000.000');
    $valid = app(WorkOrders::class)->progress($this->project, ['route_id'=>$routes[0]['id'], 'work_order_route_id'=>$routes[0]['work_order_route_id'], 'chainage_from'=>'0+100', 'chainage_to'=>'0+200']);
    expect($valid['chainage_from'])->toBe('100.000');
    expect(fn () =>app(WorkOrders::class)->progress($this->project, ['route_id'=>$routes[0]['id'], 'work_order_route_id'=>$routes[0]['work_order_route_id'], 'chainage_from'=>'0+000', 'chainage_to'=>'1+001']))->toThrow(HttpException::class);
});

it('reserves PO quantities, rejects duplicate-line over allocation and calculates a forecast margin', function (): void {
    $wo = createWorkAward($this);
    $saved = app(NativeFinance::class)->save($this->user, 'purchase-orders', awardPurchase($this, $wo));
    $po = $this->getJson($this->base.'finance/purchase-orders/'.$saved->id)->assertOk()->json();
    $this->get($this->base.'finance/purchase-orders/'.$po['id'].'/print')->assertOk()->assertSee($wo['reference'])->assertSee('Authorised road')->assertDontSee('maincon_rate');
    $summary = $this->getJson($this->base.'work-orders/'.$wo['id'])->assertOk()->json('summary');
    expect($summary['lines'][0]['available'])->toBe('400.0000')->and($summary['revenue'])->toBe('15000.00')->and($summary['forecast_gross_margin'])->toBe('5000.00');
    $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo, '401'))->assertUnprocessable();
    $duplicate = awardPurchase($this, $wo, '250');
    $duplicate['values']['lines'][] = $duplicate['values']['lines'][0];
    $this->postJson($this->base.'finance/purchase-orders', $duplicate)->assertUnprocessable();
    $this->postJson($this->base.'finance/purchase-orders/'.$po['id'].'/cancel', ['revision'=>$po['revision']])->assertOk()->assertJsonPath('state', 'canceled');
    $this->getJson($this->base.'work-orders/'.$wo['id'])->assertOk()->assertJsonPath('summary.lines.0.available', '1000.0000');
});

it('preserves approved versions, rejects reductions below reservations and approves a valid variation', function (): void {
    $wo = createWorkAward($this);
    $original = $wo['current_version_id'];
    $po = $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo))->assertCreated()->json();
    $changed = $this->award;
    $changed['reason'] = 'Variation instruction';
    $changed['lines'][0]['quantity'] = '500';
    $draft = $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions', ['request_id'=>(string) Str::uuid(), 'values'=>$changed])->assertCreated()->json('versions.0');
    $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$draft['id'].'/approve', ['revision'=>1])->assertUnprocessable();
    $changed['lines'][0]['quantity'] = '1200';
    $changed['lines'][0]['maincon_rate'] = '16';
    $changed['routes'][0]['chainage_to'] = '1+200';
    $this->putJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$draft['id'], ['revision'=>1, 'values'=>$changed])->assertOk();
    $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$draft['id'].'/approve', ['revision'=>2])->assertOk()->assertJsonPath('summary.lines.0.available', '600.0000');
    expect(WorkOrderVersion::findOrFail($original)->data['lines'][0]['quantity'])->toBe('1000.0000')->and(WorkOrderAllocation::where('order_id', $po['id'])->first()->maincon_rate)->toBe('15.0000');
    $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo, '100'))->assertConflict();
    expect(fn () =>WorkOrderVersion::findOrFail($original)->update(['state'=>'draft']))->toThrow(LogicException::class);
});

it('rejects item, source, currency and company substitutions on linked POs', function (): void {
    $wo = createWorkAward($this);
    $payload = awardPurchase($this, $wo);
    $payload['values']['lines'][0]['work_order_line_key'] = (string) Str::uuid();
    $this->postJson($this->base.'finance/purchase-orders', $payload)->assertUnprocessable();
    $payload = awardPurchase($this, $wo);
    $payload['values']['lines'][0]['item_id'] = Product::factory()->create(['company_id'=>$wo['company_id'], 'enable_purchase'=>true])->id;
    $this->postJson($this->base.'finance/purchase-orders', $payload)->assertUnprocessable();
    $this->user->syncPermissions([]);
    $this->getJson($this->base.'work-orders/'.$wo['id'])->assertForbidden();
    $this->getJson($this->base.'work-orders/form')->assertForbidden();
});

it('prevents bypassing quantity controls through the legacy model path and supports confirmation', function (): void {
    $wo = createWorkAward($this);
    $po = $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo))->assertCreated()->json();
    $order = PurchaseOrder::findOrFail($po['id']);
    expect(fn () =>$order->lines->first()->update(['product_qty'=>1200]))->toThrow(ValidationException::class);
    expect(fn () =>$order->update(['work_order_id'=>null]))->toThrow(ValidationException::class);
    $this->postJson($this->base.'finance/purchase-orders/'.$po['id'].'/confirm', ['revision'=>$po['revision']])->assertOk();
    $this->getJson($this->base.'work-orders/'.$wo['id'])->assertOk()->assertJsonPath('summary.lines.0.available', '400.0000');
});

it('allows a draft PO edit without counting its own reservation twice', function (): void {
    $wo = createWorkAward($this);
    $po = $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo))->assertCreated()->json();
    $values = awardPurchase($this, $wo, '900')['values'];
    $this->putJson($this->base.'finance/purchase-orders/'.$po['id'], ['revision'=>$po['revision'], 'values'=>$values])->assertOk();
    $this->getJson($this->base.'work-orders/'.$wo['id'])->assertOk()->assertJsonPath('summary.lines.0.available', '100.0000');
    expect(WorkOrderAllocation::where('order_id', $po['id'])->count())->toBe(1);
});

it('retains the original Work Order version on progress after a variation', function (): void {
    $wo = createWorkAward($this);
    $base = $this->base.'projects/'.$this->project->id.'/';
    $route = $this->getJson($base.'workspace')->assertOk()->json('routes.0');
    $definition = ['name'=>'User configured activity', 'unit'=>'m', 'fields'=>[['key'=>'amount', 'name'=>'My quantity title', 'type'=>'Float', 'required'=>true]], 'formula'=>'', 'outputField'=>'amount'];
    $draft = $this->postJson($base.'activities', ['revision'=>0, 'configuration'=>$definition])->assertCreated()->json('data');
    $activity = $this->postJson($base.'activities/'.$draft['id'].'/publish', ['revision'=>$draft['revision']])->assertOk()->json('data');
    $payload = ['route_id'=>$route['id'], 'work_order_route_id'=>$route['work_order_route_id'], 'chainage_from'=>'0+100', 'chainage_to'=>'0+200', 'report_date'=>'2026-11-04', 'team'=>'Site team', 'submission_key'=>(string) Str::uuid(), 'entries'=>[['activity_id'=>$activity['id'], 'version'=>1, 'inputs'=>['amount'=>'100']]]];
    $report = $this->postJson($base.'reports', $payload)->assertCreated()->assertJsonPath('data.work_order.version', 1)->json('data.id');
    $changed = $this->award;
    $changed['reason'] = 'Range variation';
    $changed['routes'][0]['chainage_to'] = '0+150';
    $v = $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions', ['request_id'=>(string) Str::uuid(), 'values'=>$changed])->assertCreated()->json('versions.0');
    $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$v['id'].'/approve', ['revision'=>1])->assertOk();
    $this->getJson($base.'reports/'.$report)->assertOk()->assertJsonPath('data.work_order.version', 1)->assertJsonPath('data.work_order.chainage_to', '200.000');
    $this->postJson($base.'reports', [...$payload, 'submission_key'=>(string) Str::uuid()])->assertUnprocessable();
    $this->postJson($base.'reports', $payload)->assertCreated()->assertJsonPath('data.id', $report);
});

it('keeps overlapping awards separate and hides commercial rates from tracker responses', function (): void {
    $first = createWorkAward($this);
    $this->award['reference'] .= '-SECOND';
    $second = createWorkAward($this);
    $workspace = $this->getJson($this->base.'projects/'.$this->project->id.'/workspace')->assertOk()->assertJsonCount(2, 'routes')->json();
    expect($workspace['routes'][0]['id'])->toBe($workspace['routes'][1]['id'])->and($workspace['routes'][0]['work_order_route_id'])->not->toBe($workspace['routes'][1]['work_order_route_id'])->and(json_encode($workspace))->not->toContain('maincon_rate', 'subcon_rate');
    $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $first, '1000'))->assertCreated();
    $this->getJson($this->base.'work-orders/'.$second['id'])->assertOk()->assertJsonPath('summary.lines.0.available', '1000.0000');
});

it('rejects cross-company and cross-project access, invalid chainages and stale drafts', function (): void {
    $bad = $this->award;
    $bad['routes'][0]['chainage_to'] = '0+1000';
    $this->postJson($this->base.'work-orders', ['request_id'=>(string) Str::uuid(), 'values'=>$bad])->assertUnprocessable();
    $wo = createWorkAward($this, false);
    $v = $wo['versions'][0];
    $this->putJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$v['id'], ['revision'=>99, 'values'=>$this->award])->assertConflict();
    $this->postJson($this->base.'work-orders/'.$wo['id'].'/versions/'.$v['id'].'/approve', ['revision'=>99])->assertConflict();
    $other = Project::factory()->create(['company_id'=>$this->user->default_company_id]);
    $this->award['project_id'] = $other->id;
    $this->postJson($this->base.'work-orders', ['request_id'=>(string) Str::uuid(), 'values'=>$this->award])->assertNotFound();
});

it('releases reservations when an unconfirmed PO awaiting approval is cancelled', function (): void {
    $wo = createWorkAward($this);
    $po = $this->postJson($this->base.'finance/purchase-orders', awardPurchase($this, $wo))->assertCreated()->json();
    app(WorkOrderPurchasing::class)->run(fn () => PurchaseOrder::findOrFail($po['id'])->update(['state' => 'to_approve']));
    $pending = $this->getJson($this->base.'finance/purchase-orders/'.$po['id'])->assertOk()->assertJsonPath('can_cancel', true)->json();
    $this->postJson($this->base.'finance/purchase-orders/'.$po['id'].'/cancel', ['revision' => $pending['revision']])->assertOk();
    $this->getJson($this->base.'work-orders/'.$wo['id'])->assertOk()->assertJsonPath('summary.lines.0.available', '1000.0000');
});
