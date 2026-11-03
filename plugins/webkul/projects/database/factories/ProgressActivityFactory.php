<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\ProgressActivity;
use Webkul\Project\Models\Project;

/** @extends Factory<ProgressActivity> */
class ProgressActivityFactory extends Factory
{
    protected $model = ProgressActivity::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'draft' => ['name' => fake()->words(2, true), 'unit' => 'pcs', 'fields' => [['key' => 'quantity', 'name' => 'Quantity', 'type' => 'Integer', 'required' => true]], 'formula' => '', 'outputField' => 'quantity'], 'revision' => 1, 'is_active' => true];
    }
}
