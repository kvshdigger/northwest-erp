<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webkul\Project\Models\ProgressReport;
use Webkul\Project\Models\ProgressRoute;

/** @extends Factory<ProgressReport> */
class ProgressReportFactory extends Factory
{
    protected $model = ProgressReport::class;

    public function definition(): array
    {
        return ['route_id' => ProgressRoute::factory(), 'project_id' => fn (array $attributes) => ProgressRoute::findOrFail($attributes['route_id'])->project_id, 'report_date' => fake()->date(), 'team' => 'Test team', 'submission_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'factory-report')];
    }
}
