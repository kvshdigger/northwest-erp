<?php

use Illuminate\Support\Str;
use Webkul\Project\Models\ProgressActivity;
use Webkul\Project\Models\ProgressEntry;
use Webkul\Project\Models\ProgressMembership;
use Webkul\Project\Models\ProgressReport;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

require_once __DIR__.'/../../../WorkOrderFixtures.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    config(['project-progress.enabled' => true]);
});

function progressProject(): Project
{
    $user = SecurityHelper::authenticateWithPermissions([])->refresh();

    return Project::factory()->create(['user_id' => $user->id, 'creator_id' => $user->id, 'company_id' => $user->default_company_id]);
}

function progressUrl(Project $project, string $path): string
{
    return '/admin/api/v2/project-progress/projects/'.$project->id.'/'.$path;
}

function progressDefinition(): array
{
    return ['name' => 'Excavation', 'unit' => 'm³', 'fields' => [
        ['key' => 'start', 'name' => 'Starting reference / 起点', 'type' => 'Float', 'required' => true],
        ['key' => 'end', 'name' => 'Ending reference', 'type' => 'Float', 'required' => true],
        ['key' => 'width', 'name' => 'Width', 'type' => 'Float', 'required' => true, 'min' => 0, 'max' => 100],
        ['key' => 'note', 'name' => 'Site notes', 'type' => 'Character', 'required' => false],
    ], 'formula' => '(end - start) * width', 'outputField' => 'start'];
}

function publishProgressActivity($test, Project $project, ?array $definition = null): array
{
    $draft = $test->postJson(progressUrl($project, 'activities'), ['revision' => 0, 'configuration' => $definition ?? progressDefinition()])->assertCreated()->json('data');

    return $test->postJson(progressUrl($project, 'activities/'.$draft['id'].'/publish'), ['revision' => $draft['revision']])->assertOk()->json('data');
}

function progressPayload(Project $project, array $activity, ?ProgressRoute $route = null): array
{
    $route ??= ProgressRoute::factory()->create(['project_id' => $project->id]);

    return ['work_order_route_id' => authoriseProgressTestRoute($project, $route), 'chainage_from' => '0', 'chainage_to' => '10', 'route_id' => $route->id, 'report_date' => '2026-11-03', 'team' => 'Team A', 'submission_key' => (string) Str::uuid(), 'entries' => [
        ['activity_id' => $activity['id'], 'version' => $activity['published']['version'], 'inputs' => ['start' => '0.1', 'end' => '10.1', 'width' => '2.5']],
    ]];
}

it('requires authentication and keeps the new API disabled unless explicitly enabled', function (): void {
    $this->getJson('/admin/api/v2/project-progress/projects')->assertUnauthorized();
    progressProject();
    config(['project-progress.enabled' => false]);
    $this->getJson('/admin/api/v2/project-progress/projects')->assertNotFound();
});

it('lists only assigned projects and rejects cross-project reads and writes', function (): void {
    $own = progressProject();
    $other = Project::factory()->create(['user_id' => User::factory(), 'company_id' => $own->company_id]);
    $this->getJson('/admin/api/v2/project-progress/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson(progressUrl($other, 'workspace'))->assertForbidden();
    $this->postJson(progressUrl($other, 'activities'), ['revision' => 0, 'configuration' => progressDefinition()])->assertForbidden();
});

it('preserves published settings while drafts are edited and detects stale revisions', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $definition = progressDefinition();
    $definition['fields'][0]['name'] = 'Renamed input';
    $definition['formula'] = 'end - start';
    $saved = $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => $definition])->assertOk()->json('data');
    expect($saved['published']['configuration']['formula'])->toBe('(end - start) * width')
        ->and($saved['draft']['formula'])->toBe('end - start');
    $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => $definition])->assertConflict();
    $this->postJson(progressUrl($project, 'activities/'.$activity['id'].'/publish'), ['revision' => $activity['revision']])->assertConflict();
    $this->postJson(progressUrl($project, 'activities/'.$activity['id'].'/publish'), ['revision' => $saved['revision']])->assertOk()->assertJsonPath('data.published.version', 2);
    expect(ProgressActivity::findOrFail($activity['id'])->versions()->count())->toBe(2);
});

it('allows incomplete drafts but rejects invalid publication without altering the published version', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $definition = progressDefinition();
    $definition['formula'] = 'system(1)';
    $saved = $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => $definition])->assertOk()->json('data');
    $this->postJson(progressUrl($project, 'activities/'.$activity['id'].'/publish'), ['revision' => $saved['revision']])->assertUnprocessable();
    expect(ProgressActivity::findOrFail($activity['id'])->published->number)->toBe(1);
});

it('does not expose unpublished drafts or grant configuration rights to ordinary members', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $this->postJson(progressUrl($project, 'activities'), ['revision' => 0, 'configuration' => progressDefinition()])->assertCreated();
    $member = SecurityHelper::authenticateWithPermissions([])->refresh();
    ProgressMembership::factory()->create(['project_id' => $project->id, 'user_id' => $member->id]);
    $this->getJson(progressUrl($project, 'workspace'))->assertOk()->assertJsonCount(1, 'activities')->assertJsonMissingPath('activities.0.draft')->assertJsonPath('capabilities.can_configure', false);
    $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => progressDefinition()])->assertForbidden();
});

it('records multiple entries atomically with server calculations and per-team attribution', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $payload['entries'][] = [...$payload['entries'][0], 'team' => 'Team B', 'location' => 'Second location', 'inputs' => ['start' => 10, 'end' => 15, 'width' => 2, 'note' => 'Wet ground']];
    $response = $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.entries.0.result', '25.000000')->assertJsonPath('data.entries.1.result', '10.000000')->assertJsonPath('data.entries.1.team', 'Team B')->assertJsonPath('data.entries.0.status', 'pending');
    $this->getJson(progressUrl($project, 'reports/'.$response->json('data.id')))->assertOk()->assertJsonPath('data.entries.1.inputs.note', 'Wet ground');
    $this->getJson(progressUrl($project, 'tracker'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activities.0.recorded', '35.000000')->assertJsonPath('data.0.activities.0.approved', '0.000000')->assertJsonPath('data.0.activities.0.claimable', null);
});

it('rolls back the whole report when any entry is invalid and rejects client-supplied results or approval', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $payload['entries'][] = [...$payload['entries'][0], 'inputs' => ['start' => 1, 'end' => 3, 'width' => -1]];
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertUnprocessable();
    expect(ProgressReport::count())->toBe(0)->and(ProgressEntry::count())->toBe(0);
    array_pop($payload['entries']);
    $payload['entries'][0]['result_numeric'] = '999';
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertUnprocessable();
    unset($payload['entries'][0]['result_numeric']);
    $payload['status'] = 'approved';
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertUnprocessable();
});

it('deduplicates retries and rejects reuse of a submission key for another payload', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $first = $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->json('data.id');
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.id', $first);
    expect(ProgressReport::count())->toBe(1)->and(ProgressEntry::count())->toBe(1);
    $payload['team'] = 'Different team';
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertConflict();
});

it('preserves historical versions and rejects a newly submitted stale form', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $report = $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->json('data.id');
    $definition = progressDefinition();
    $definition['formula'] = '(end - start) * 3';
    $saved = $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => $definition])->assertOk()->json('data');
    $this->postJson(progressUrl($project, 'activities/'.$activity['id'].'/publish'), ['revision' => $saved['revision']])->assertOk();
    $this->getJson(progressUrl($project, 'reports/'.$report))->assertOk()->assertJsonPath('data.entries.0.result', '25.000000')->assertJsonPath('data.entries.0.version', 1);
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.id', $report);
    $payload['submission_key'] = (string) Str::uuid();
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertConflict();
    $payload['entries'][0]['version'] = 2;
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.entries.0.result', '30.000000');
});

it('rejects cross-project route, activity and report identifiers even when the user owns both projects', function (): void {
    $project = progressProject();
    $other = Project::factory()->create(['user_id' => auth()->id(), 'company_id' => $project->company_id]);
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $this->postJson(progressUrl($other, 'reports'), $payload)->assertUnprocessable();
    $payload['route_id'] = ProgressRoute::factory()->create(['project_id' => $other->id])->id;
    $this->postJson(progressUrl($other, 'reports'), $payload)->assertUnprocessable();
    $this->postJson(progressUrl($other, 'activities/'.$activity['id'].'/publish'), ['revision' => 2])->assertNotFound();
});

it('supports text-only activities without requiring chainage or numeric outputs', function (): void {
    $project = progressProject();
    $definition = ['name' => 'Inspection note', 'unit' => '', 'fields' => [['key' => 'note', 'name' => 'Observation', 'type' => 'Character', 'required' => true]], 'formula' => '', 'outputField' => 'note'];
    $activity = publishProgressActivity($this, $project, $definition);
    $payload = progressPayload($project, $activity);
    $payload['entries'][0]['inputs'] = ['note' => 'Work complete'];
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.entries.0.result', 'Work complete');
    $this->getJson(progressUrl($project, 'tracker'))->assertOk()->assertJsonPath('data.0.activities.0.recorded', ['Work complete']);
});

it('groups separate reports by route and date and paginates complete daily groups', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated();
    $payload['submission_key'] = (string) Str::uuid();
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated();
    $payload['submission_key'] = (string) Str::uuid();
    $payload['report_date'] = '2026-11-02';
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated();
    $this->getJson(progressUrl($project, 'tracker').'?date_from=2026-11-03')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activities.0.recorded', '50.000000')->assertJsonPath('meta.total', 1);
    $this->getJson(progressUrl($project, 'tracker').'?date_to=2026-11-02')->assertOk()->assertJsonCount(1, 'data');
});

it('audits configuration changes and prevents ordinary edits to published versions and recorded inputs', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated();
    $model = ProgressActivity::findOrFail($activity['id']);
    expect(fn () => $model->published->update(['definition' => []]))->toThrow(LogicException::class)
        ->and(fn () => ProgressEntry::firstOrFail()->update(['result_numeric' => '999']))->toThrow(LogicException::class)
        ->and($project->getConnection()->table('projects_progress_events')->where('project_id', $project->id)->orderBy('id')->pluck('event')->all())->toBe(['draft_saved', 'published', 'report_created']);
});

it('grants company-scoped management access only through explicit permissions', function (): void {
    $project = progressProject();
    $other = Project::factory()->create(['user_id' => User::factory(), 'company_id' => $project->company_id]);
    $foreignCompany = Company::factory()->create();
    $foreign = Project::factory()->create(['user_id' => User::factory(), 'company_id' => $foreignCompany->id]);
    SecurityHelper::authenticateWithPermissions(['view_all_project_progress', 'manage_project_progress'])->refresh();
    $this->getJson('/admin/api/v2/project-progress/projects')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(progressUrl($other, 'workspace'))->assertOk()->assertJsonPath('capabilities.can_configure', true);
    $this->getJson(progressUrl($foreign, 'workspace'))->assertNotFound();
});

it('creates project-specific routes and prevents duplicate names regardless of case', function (): void {
    $project = progressProject();
    $this->postJson(progressUrl($project, 'routes'), ['name' => 'North route'])->assertCreated()->assertJsonPath('data.name', 'North route');
    $this->postJson(progressUrl($project, 'routes'), ['name' => 'NORTH ROUTE'])->assertUnprocessable();
    $this->getJson(progressUrl($project, 'workspace'))->assertOk()->assertJsonCount(0, 'routes');
});

it('lets project managers assign members but not grant another company access', function (): void {
    $project = progressProject();
    $member = User::withoutEvents(fn () => User::factory()->create(['is_active' => true, 'default_company_id' => $project->company_id]));
    $member->allowedCompanies()->sync([$project->company_id]);
    $this->putJson(progressUrl($project, 'members'), ['user_id' => $member->id, 'can_submit' => false, 'can_configure' => false])->assertOk();
    $member->allowedCompanies()->detach();
    $this->putJson(progressUrl($project, 'members'), ['user_id' => $member->id, 'can_submit' => true, 'can_configure' => true])->assertUnprocessable();
});

it('enforces read-only membership and blocks escalation through membership settings', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $member = SecurityHelper::authenticateWithPermissions([])->refresh();
    ProgressMembership::factory()->create(['project_id' => $project->id, 'user_id' => $member->id, 'can_submit' => false]);
    $this->getJson(progressUrl($project, 'workspace'))->assertOk()->assertJsonPath('capabilities.can_submit', false);
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertForbidden();
    $this->putJson(progressUrl($project, 'members'), ['user_id' => $member->id, 'can_submit' => true, 'can_configure' => true])->assertForbidden();
    $member->forceFill(['is_active' => false])->save();
    $this->getJson(progressUrl($project, 'workspace'))->assertForbidden();
});

it('validates integer and datetime input types with optional metadata', function (): void {
    $project = progressProject();
    $definition = ['name' => 'Installed units', 'unit' => 'pcs', 'fields' => [
        ['key' => 'units', 'name' => 'Units', 'type' => 'Integer', 'required' => true, 'min' => 1, 'max' => 10],
        ['key' => 'time', 'name' => 'Inspection time', 'type' => 'Datetime', 'required' => false],
    ], 'formula' => '', 'outputField' => 'units'];
    $activity = publishProgressActivity($this, $project, $definition);
    $payload = progressPayload($project, $activity);
    foreach ([['units' => '1.5'], ['units' => '11'], ['units' => '2', 'time' => '2026-02-30T12:00'], ['units' => '2', 'unknown' => 'not allowed']] as $inputs) {
        $payload['entries'][0]['inputs'] = $inputs;
        $this->postJson(progressUrl($project, 'reports'), $payload)->assertUnprocessable();
    }
    $payload['entries'][0]['inputs'] = ['units' => '2.0', 'time' => '2026-11-03T12:00'];
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.entries.0.result', '2.000000');
});

it('blocks incompatible unit or output-category changes without changing historical configurations', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $definition = progressDefinition();
    $definition['unit'] = 'pcs';
    $saved = $this->putJson(progressUrl($project, 'activities/'.$activity['id'].'/draft'), ['revision' => $activity['revision'], 'configuration' => $definition])->assertOk()->json('data');
    $this->postJson(progressUrl($project, 'activities/'.$activity['id'].'/publish'), ['revision' => $saved['revision']])->assertUnprocessable();
    expect(ProgressActivity::findOrFail($activity['id'])->published->definition['unit'])->toBe('m³');
});

it('does not round small JSON numeric inputs before calculation', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $payload = progressPayload($project, $activity);
    $payload['entries'][0]['inputs'] = ['start' => 0, 'end' => 0.000001, 'width' => 2];
    $this->postJson(progressUrl($project, 'reports'), $payload)->assertCreated()->assertJsonPath('data.entries.0.result', '0.000002');
});

it('paginates complete daily groups and keeps approved quantities separate across reports', function (): void {
    $project = progressProject();
    $activity = publishProgressActivity($this, $project);
    $version = ProgressActivity::findOrFail($activity['id'])->published;
    $route = ProgressRoute::factory()->create(['project_id' => $project->id]);
    foreach (range(1, 31) as $day) {
        $report = ProgressReport::factory()->create(['project_id' => $project->id, 'route_id' => $route->id, 'report_date' => sprintf('2026-10-%02d', $day)]);
        ProgressEntry::factory()->create(['report_id' => $report->id, 'version_id' => $version->id, 'result_numeric' => '1.000000']);
    }
    $report = ProgressReport::factory()->create(['project_id' => $project->id, 'route_id' => $route->id, 'report_date' => '2026-10-31']);
    ProgressEntry::factory()->create(['report_id' => $report->id, 'version_id' => $version->id, 'result_numeric' => '7.000000', 'status' => 'approved']);
    $this->getJson(progressUrl($project, 'tracker'))->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 31)
        ->assertJsonPath('data.0.entry_count', 2)->assertJsonPath('data.0.status', 'mixed')
        ->assertJsonPath('data.0.activities.0.recorded', '8.000000')->assertJsonPath('data.0.activities.0.approved', '7.000000')->assertJsonPath('data.0.activities.0.claimable', null);
    $this->getJson(progressUrl($project, 'tracker').'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.report_date', '2026-10-01');
});

it('previews server calculations without saving configurations or progress', function (): void {
    $project = progressProject();
    $definition = progressDefinition();
    $definition['formula'] = 'end / (width - start)';
    $this->postJson(progressUrl($project, 'activities/preview'), ['configuration' => $definition, 'inputs' => ['start' => 1, 'end' => 8, 'width' => 3]])
        ->assertOk()->assertJsonPath('data.result', '4.000000');
    $this->postJson(progressUrl($project, 'activities/preview'), ['configuration' => $definition, 'inputs' => ['start' => 1, 'end' => 8, 'width' => 1]])->assertUnprocessable();
    expect(ProgressActivity::count())->toBe(0)->and(ProgressReport::count())->toBe(0);
});
