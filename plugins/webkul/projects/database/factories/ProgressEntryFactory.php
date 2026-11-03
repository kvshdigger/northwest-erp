<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\ProgressEntry;
use Webkul\Project\Models\ProgressReport;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\ProgressVersion;

/** @extends Factory<ProgressEntry> */
class ProgressEntryFactory extends Factory
{
    protected $model = ProgressEntry::class;

    public function definition(): array
    {
        return ['version_id' => ProgressVersion::factory(), 'report_id' => function (array $attributes): int {
            $version = ProgressVersion::with('activity')->findOrFail($attributes['version_id']);

            return ProgressReport::factory()->create(['route_id' => ProgressRoute::factory()->create(['project_id' => $version->activity->project_id])->id])->id;
        }, 'inputs' => ['quantity' => '1'], 'result_type' => 'Integer', 'result_numeric' => '1.000000', 'team' => 'Test team', 'status' => 'pending'];
    }
}
