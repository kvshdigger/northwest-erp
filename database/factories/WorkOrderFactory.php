<?php

namespace Database\Factories;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webkul\Partner\Models\Partner;
use Webkul\Project\Models\Project;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id'  => Company::query()->value('id') ?? Company::factory(),
            'project_id'  => Project::factory(),
            'partner_id'  => Partner::factory(),
            'currency_id' => Currency::query()->value('id') ?? Currency::factory(),
            'reference'   => 'TEST-WO-'.Str::uuid(),
            'state'       => 'active',
        ];
    }
}
