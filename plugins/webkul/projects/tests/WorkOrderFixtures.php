<?php

use App\Models\WorkOrder;
use App\Models\WorkOrderRoute;
use App\Models\WorkOrderVersion;
use Illuminate\Support\Str;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Support\Models\Company;

function authoriseProgressTestRoute(Project $project, ProgressRoute $route): int
{
    if ($existing = WorkOrderRoute::where('route_id', $route->id)->first()) {
        return $existing->id;
    }
    $company = Company::findOrFail($project->company_id);
    $wo = WorkOrder::factory()->create(['company_id' => $company->id, 'project_id' => $project->id, 'partner_id' => $company->partner_id, 'currency_id' => $company->currency_id]);
    $version = WorkOrderVersion::factory()->create(['work_order_id' => $wo->id, 'creator_id' => $project->user_id, 'state' => 'approved']);
    $wo->update(['current_version_id' => $version->id]);

    return WorkOrderRoute::create(['version_id' => $version->id, 'route_id' => $route->id, 'source_key' => (string) Str::uuid(), 'chainage_from' => '0', 'chainage_to' => '100000'])->id;
}
