<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\ProgressActivity;
use Webkul\Project\Models\ProgressVersion;

/** @extends Factory<ProgressVersion> */
class ProgressVersionFactory extends Factory
{
    protected $model = ProgressVersion::class;

    public function definition(): array
    {
        return ['activity_id' => ProgressActivity::factory(), 'number' => 1, 'definition' => ['name' => 'Recorded units', 'unit' => 'pcs', 'fields' => [['key' => 'quantity', 'name' => 'Quantity', 'type' => 'Integer', 'required' => true]], 'formula' => '', 'outputField' => 'quantity']];
    }
}
