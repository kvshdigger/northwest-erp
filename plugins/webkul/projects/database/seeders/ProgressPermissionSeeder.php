<?php

namespace Webkul\Project\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Security\Models\Permission;

class ProgressPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['web', 'sanctum'] as $guard) {
            foreach (['view_all_project_progress', 'manage_project_progress'] as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
