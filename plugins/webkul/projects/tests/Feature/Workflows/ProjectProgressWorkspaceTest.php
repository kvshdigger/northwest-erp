<?php

use Illuminate\Support\Str;
use Webkul\Project\Models\ProgressMembership;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

require_once __DIR__.'/../../WorkOrderFixtures.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    config(['project-progress.enabled' => true]);
});

it('serves the connected workspace through the ERP session and protects its files', function (): void {
    $this->get('/admin/v2/project-progress')->assertRedirect();
    $user = SecurityHelper::authenticateWithPermissions([])->refresh();
    $this->get('/admin/v2/project-progress')->assertOk()->assertSee('name="progress-api"', false)->assertSee('name="csrf-token"', false)->assertHeader('Cache-Control', 'no-store, private');
    expect($this->get('/admin/v2/project-progress/files/api.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')->baseResponse->getFile()->getFilename())->toBe('api.js');
    $this->get('/admin/v2/project-progress/files/index.html')->assertNotFound();
    $this->get('/admin/v2/project-progress/files/../../.env')->assertNotFound();
    $user->forceFill(['is_active' => false])->save();
    $this->get('/admin/v2/project-progress')->assertForbidden();
    config(['project-progress.enabled' => false]);
    $this->get('/admin/v2/project-progress')->assertNotFound();
});

it('connects session-based project setup, publication, entry storage and tracker reload', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([])->refresh();
    $project = Project::factory()->create(['user_id' => $user->id, 'company_id' => $user->default_company_id]);
    $base = '/admin/v2/project-progress/api/projects/'.$project->id.'/';
    $this->getJson('/admin/v2/project-progress/api/projects')->assertOk()->assertJsonPath('data.0.id', $project->id);
    $route = $this->postJson($base.'routes', ['name' => 'Local route'])->assertCreated()->json('data.id');
    $configuration = ['name' => 'Custom measurement', 'unit' => 'm²', 'fields' => [
        ['key' => 'length', 'name' => 'User length title', 'type' => 'Float', 'required' => true],
        ['key' => 'width', 'name' => 'User width title', 'type' => 'Float', 'required' => true],
    ], 'formula' => 'length * width', 'outputField' => 'length'];
    $activity = $this->postJson($base.'activities', ['configuration' => $configuration, 'revision' => 0])->assertCreated()->json('data');
    $this->postJson($base.'activities/'.$activity['id'].'/publish', ['revision' => $activity['revision']])->assertOk();
    $this->postJson($base.'reports', ['work_order_route_id' => authoriseProgressTestRoute($project, ProgressRoute::findOrFail($route)), 'chainage_from' => '0', 'chainage_to' => '10', 'route_id' => $route, 'report_date' => '2026-11-03', 'team' => 'Site team', 'submission_key' => (string) Str::uuid(), 'entries' => [
        ['activity_id' => $activity['id'], 'version' => 1, 'inputs' => ['length' => '0.1', 'width' => '3']],
    ]])->assertCreated()->assertJsonPath('data.entries.0.result', '0.300000');
    $this->getJson($base.'workspace')->assertOk()->assertJsonPath('activities.0.published.configuration.fields.0.name', 'User length title');
    $this->getJson($base.'tracker')->assertOk()->assertJsonPath('data.0.activities.0.recorded', '0.300000');
    $route = app('router')->getRoutes()->getByName('admin.v2.project-progress.reports.store');
    expect($route->gatherMiddleware())->toContain('web', 'auth:web');
});

it('exposes member administration data only to project managers', function (): void {
    $manager = SecurityHelper::authenticateWithPermissions([])->refresh();
    $project = Project::factory()->create(['user_id' => $manager->id, 'company_id' => $manager->default_company_id]);
    $member = User::withoutEvents(fn () => User::factory()->create(['is_active' => true, 'default_company_id' => $project->company_id]));
    $member->allowedCompanies()->sync([$project->company_id]);
    ProgressMembership::factory()->create(['project_id' => $project->id, 'user_id' => $member->id, 'can_configure' => true]);
    $base = '/admin/v2/project-progress/api/projects/'.$project->id.'/';
    $this->getJson($base.'workspace')->assertOk()->assertJsonPath('members.0.name', $member->name);
    $this->actingAs($member);
    $this->getJson($base.'workspace')->assertOk()->assertJsonPath('capabilities.can_manage_members', false)->assertJsonCount(0, 'members')->assertJsonCount(0, 'member_candidates');
    $this->putJson($base.'members', ['user_id' => $member->id, 'can_submit' => true, 'can_configure' => true])->assertForbidden();
});
