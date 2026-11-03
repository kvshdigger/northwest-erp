<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;

/** @extends Factory<ProgressRoute> */
class ProgressRouteFactory extends Factory
{
    protected $model = ProgressRoute::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'name' => $name = fake()->unique()->words(3, true), 'name_key' => mb_strtolower($name), 'is_active' => true];
    }
}
