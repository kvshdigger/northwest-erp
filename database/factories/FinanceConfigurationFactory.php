<?php

namespace Database\Factories;

use App\Models\FinanceConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<FinanceConfiguration>
 */
class FinanceConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'revision'   => 1,
            'settings'   => ['display_name' => fake()->company()],
        ];
    }
}
