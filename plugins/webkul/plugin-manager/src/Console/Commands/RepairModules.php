<?php

namespace Webkul\PluginManager\Console\Commands;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

class RepairModules extends Command
{
    protected $signature = 'erp:repair-modules';

    protected $description = 'Install active ERP modules that are not yet installed and refresh administrator permissions';

    public function handle(): int
    {
        if (! Schema::hasTable('plugins')) {
            $this->error('The plugins table does not exist. Run the normal ERP installation first.');

            return self::FAILURE;
        }

        $availableCommands = array_keys(Artisan::all());
        $attempted = [];
        $failed = [];

        // Each package install command handles its declared dependencies and
        // runs only migrations that are not already recorded as completed.
        while (true) {
            $pending = Plugin::query()
                ->where('is_active', true)
                ->where('is_installed', false)
                ->orderBy('sort')
                ->get();

            $next = $pending->first(fn (Plugin $plugin) =>
                ! in_array($plugin->name, $attempted, true)
                && in_array($plugin->name.':install', $availableCommands, true)
            );

            if (! $next) {
                break;
            }

            $attempted[] = $next->name;
            $command = $next->name.':install';

            $this->info("Installing module: {$next->name}");

            try {
                $exitCode = Artisan::call($command);

                if ($exitCode !== self::SUCCESS) {
                    $failed[] = $next->name;
                    $this->error("Installation command failed for {$next->name} (exit code {$exitCode}).");
                }
            } catch (Throwable $exception) {
                $failed[] = $next->name;
                report($exception);
                $this->error("Installation failed for {$next->name}: {$exception->getMessage()}");
            }
        }

        $notInstalled = Plugin::query()
            ->where('is_active', true)
            ->where('is_installed', false)
            ->pluck('name')
            ->all();

        if ($notInstalled !== []) {
            $this->warn('These active modules remain uninstalled: '.implode(', ', $notInstalled));
        }

        // Refresh the generated permission list and explicitly grant the
        // configured panel-admin role access to all discovered admin resources.
        try {
            $exitCode = Artisan::call('shield:generate', [
                '--all' => true,
                '--option' => 'permissions',
                '--panel' => 'admin',
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error('Shield permission generation failed.');

                return self::FAILURE;
            }

            $adminRoleName = Utils::getPanelUserRoleName();
            $adminRole = Role::query()->where('name', $adminRoleName)->first()
                ?? Role::query()->where('name', 'panel_user')->first()
                ?? Role::query()->where('is_default', true)->first();

            if (! $adminRole) {
                $this->error("No administrator role was found (checked '{$adminRoleName}', 'panel_user', and the default role).");
                $this->line('Existing roles: '.Role::query()->pluck('name')->implode(', '));

                return self::FAILURE;
            }

            $adminRoleName = $adminRole->name;

            $adminRole->syncPermissions(Permission::query()->get());
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Package::refreshPluginCaches();

            $this->info("Administrator permissions refreshed for role '{$adminRoleName}'.");
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Could not refresh administrator permissions: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($failed !== []) {
            $this->error('Some modules failed to install: '.implode(', ', array_unique($failed)));

            return self::FAILURE;
        }

        $this->info('Module installation and administrator permission repair completed.');

        return self::SUCCESS;
    }
}
