<?php

namespace Database\Factories;

use App\Models\WorkOrder;
use App\Models\WorkOrderVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;

/**
 * @extends Factory<WorkOrderVersion>
 */
class WorkOrderVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'number'        => 1, 'revision' => 1, 'state' => 'draft',
            'creator_id'    => User::query()->value('id') ?? User::factory(),
            'data'          => ['title' => 'Test award', 'reason' => 'Test fixture', 'routes' => [], 'lines' => []],
        ];
    }
}
