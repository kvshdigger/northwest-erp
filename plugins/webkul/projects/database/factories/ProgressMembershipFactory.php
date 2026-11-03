<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\ProgressMembership;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\User;

/** @extends Factory<ProgressMembership> */
class ProgressMembershipFactory extends Factory
{
    protected $model = ProgressMembership::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'user_id' => User::factory(), 'can_submit' => true, 'can_configure' => false];
    }
}
