<?php

use App\Models\DailyProgressReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Webkul\Project\Models\Project;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

it('installs project progress after core report tables without rerunning an applied migration', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('projects');

    expect(Schema::hasTable('daily_progress_reports'))->toBeTrue()
        ->and(Schema::hasTable('projects_projects'))->toBeTrue()
        ->and(Schema::hasColumn('daily_progress_reports', 'project_id'))->toBeTrue()
        ->and(Schema::hasTable('project_progress_activities'))->toBeTrue();

    $project = Project::factory()->create();
    $report = DailyProgressReport::query()->create([
        'project_id'    => $project->id,
        'report_date'   => '2026-11-03',
        'project_route' => 'Retained route',
    ]);

    $migration = '2026_10_30_000001_add_project_specific_progress_activities';
    $before = app('migration.repository')->getRan();

    Artisan::call('migrate', [
        '--path'           => 'plugins/webkul/projects/database/migrations/'.$migration.'.php',
        '--force'          => true,
        '--no-interaction' => true,
    ]);

    expect(app('migration.repository')->getRan())->toBe($before)
        ->and(array_count_values($before)[$migration] ?? 0)->toBe(1)
        ->and($report->fresh()->project_id)->toBe($project->id)
        ->and($report->fresh()->project_route)->toBe('Retained route');
});
