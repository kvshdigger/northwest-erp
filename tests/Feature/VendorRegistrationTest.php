<?php

use App\Models\VendorRegistration;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Purchase\Models\Partner;
use Webkul\Security\Models\Role;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';
beforeEach(function () {
    foreach (['projects', 'products', 'accounting', 'purchases'] as $plugin) {
        TestBootstrapHelper::ensurePluginInstalled($plugin);
    }config(['project-progress.enabled' => true]);
    $this->user = SecurityHelper::authenticateWithPermissions(['view_any_purchase_vendor', 'create_purchase_vendor'])->refresh();
    $this->base = '/admin/v2/project-progress/api/vendor-registrations';
});
it('holds self-registration for review and approves exactly one vendor', function () {
    $url = $this->postJson($this->base.'/invite', ['company_id' => $this->user->default_company_id])->assertOk()->json('url');
    $path = parse_url($url, PHP_URL_PATH);
    Auth::logout();
    $this->get($path)->assertOk()->assertSee('Subcontractor registration');
    $payload = ['name' => 'New subcontractor', 'account_type' => 'company', 'company_registry' => 'SSM-TEST', 'email' => 'subcon@example.test', 'contact_person' => 'Site contact'];
    $this->post($path, $payload)->assertOk()->assertSee('submitted for review');
    $r = VendorRegistration::latest('id')->firstOrFail();
    expect($r->state)->toBe('pending');
    expect(Partner::where('name', 'New subcontractor')->exists())->toBeFalse();
    $this->actingAs($this->user);
    $this->postJson($this->base.'/'.$r->id.'/approve')->assertOk();
    expect(Partner::where('name', 'New subcontractor')->count())->toBe(1);
    $this->postJson($this->base.'/'.$r->id.'/approve')->assertConflict();
    $this->get($path)->assertGone();
});
it('rejects expired invitation links and invalid submissions', function () {
    $url = $this->postJson($this->base.'/invite', ['company_id' => $this->user->default_company_id])->assertOk()->json('url');
    $path = parse_url($url, PHP_URL_PATH);
    $this->postJson($path, [])->assertUnprocessable();
    $r = VendorRegistration::latest('id')->first();
    $r->update(['expires_at' => now()->subMinute()]);
    $this->get($path)->assertOk()->assertSee('This link has expired');
});
it('rejects invitation creation outside permitted companies', function () {
    $this->postJson($this->base.'/invite', ['company_id' => 999999])->assertForbidden();
});

it('expires invitations after 24 hours and allows requesting a replacement without opening ERP access', function () {
    $url = $this->postJson($this->base.'/invite', ['company_id'=>$this->user->default_company_id])->assertOk()->json('url');
    $r = VendorRegistration::latest('id')->firstOrFail();
    expect(now()->diffInHours($r->expires_at))->toBeGreaterThan(23)->toBeLessThanOrEqual(24);
    $path = parse_url($url, PHP_URL_PATH);
    $this->travel(25)->hours();
    Auth::logout();
    $this->get($path)->assertOk()->assertSee('Request link');
    $this->post($path.'/request-link')->assertOk()->assertSee('request was sent');
    expect($r->fresh()->state)->toBe('link_requested');
    $this->actingAs($this->user);
    $new = $this->postJson($this->base.'/'.$r->id.'/resend')->assertOk()->json('url');
    expect($new)->not->toBe($url);
    $this->get($path)->assertNotFound();
    $this->get(parse_url($new, PHP_URL_PATH))->assertOk()->assertSee('Submit for review');
});

it('lets active administrators review company registrations without vendor creation permissions', function () {
    $r = VendorRegistration::create(['creator_id'=>$this->user->id, 'company_id'=>$this->user->default_company_id, 'token_hash'=>hash('sha256', random_bytes(32)), 'expires_at'=>now()->addDay(), 'state'=>'pending', 'data'=>['name'=>'Admin reviewed subcon', 'account_type'=>'company', 'company_registry'=>'SSM-ADMIN', 'email'=>'admin-reviewed@example.test', 'contact_person'=>'Contact']]);
    $this->user->syncPermissions([]);
    $this->user->assignRole(Role::firstOrCreate(['name'=>'admin', 'guard_name'=>'web']));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->user->unsetRelation('roles')->unsetRelation('permissions');
    $this->getJson('/admin/v2/project-progress/api/vendors')->assertOk();
    $this->getJson($this->base)->assertOk()->assertJsonPath('can_review', true);
    $this->postJson($this->base.'/'.$r->id.'/approve')->assertOk();
    expect($r->fresh()->reviewer_id)->toBe($this->user->id);
});

it('keeps administrator registration approval scoped to their companies', function () {
    $company = Company::factory()->create();
    $r = VendorRegistration::create(['creator_id'=>$this->user->id, 'company_id'=>$company->id, 'token_hash'=>hash('sha256', random_bytes(32)), 'expires_at'=>now()->addDay(), 'state'=>'pending']);
    $this->user->assignRole(Role::firstOrCreate(['name'=>'admin', 'guard_name'=>'web']));
    $this->postJson($this->base.'/'.$r->id.'/approve')->assertNotFound();
    expect($r->fresh()->state)->toBe('pending');
});
