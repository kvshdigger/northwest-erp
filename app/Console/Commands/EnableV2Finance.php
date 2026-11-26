<?php

namespace App\Console\Commands;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class EnableV2Finance extends Command
{
    protected $signature = 'v2:enable-finance';

    protected $description = 'Install Finance schema and activate dependencies without running seeders or deleting records';

    public function handle(): int
    {
        $packages = [];
        foreach (app()->getProviders(PackageServiceProvider::class) as $provider) {
            $package = new Package;
            $package->setBasePath(dirname((new \ReflectionClass($provider))->getFileName()));
            $provider->configureCustomPackage($package);
            $packages[$package->name] = $package;
        }
        $visited = [];
        $install = function (string $name) use (&$install, &$visited, $packages): void {
            if (isset($visited[$name])) {
                return;
            }
            $package = $packages[$name] ?? throw new \RuntimeException('Missing module manifest: '.$name);
            $visited[$name] = true;
            foreach ($package->dependencies as $dependency) {
                $install($dependency);
            }
            foreach (['migrationFileNames'=>'migrations', 'settingFileNames'=>'settings'] as $property=>$directory) {
                $paths = [];
                foreach ($package->$property as $file) {
                    $path = $package->basePath('../database/'.$directory.'/'.$file.'.php');
                    if (! is_file($path)) {
                        throw new \RuntimeException('Missing migration: '.$file);
                    }
                    $paths[] = Str::after($path, base_path().DIRECTORY_SEPARATOR);
                }
                if ($paths && $this->call('migrate', ['--path'=>$paths, '--force'=>true, '--no-interaction'=>true]) !== 0) {
                    throw new \RuntimeException('Migration failed: '.$name);
                }
            }
            $plugin = $package->updateOrCreate();
            foreach ($package->dependencies as $dependency) {
                $plugin->dependencies()->syncWithoutDetaching(Package::getPackagePlugin($dependency));
            }
            $this->info('Enabled '.$name.' (no seeders executed).');
        };
        foreach (['purchases', 'accounting', 'products'] as $name) {
            $install($name);
        }
        foreach (['purchases_orders', 'products_products', 'accounts_accounts', 'accounts_account_moves'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Required table missing: '.$table);
            }
        }
        if ($this->call('shield:generate', ['--all'=>true, '--option'=>'permissions', '--panel'=>'admin', '--no-interaction'=>true]) !== 0) {
            return self::FAILURE;
        }
        $admin = Role::where('name', Utils::getPanelUserRoleName())->first();
        if ($admin) {
            $permissions = Permission::where(function ($q) {
                foreach (['purchase', 'accounting', 'product'] as $scope) {
                    $q->orWhere('name', 'like', '%_'.$scope.'_%');
                }
            })->get();
            $admin->givePermissionTo($permissions);
            $admin->givePermissionTo(Permission::findOrCreate('v2_finance_configure', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Package::refreshPluginCaches();
        $this->info('V2 Finance activated. Existing records and numbering preserved. Configure accounting defaults if empty.');

        return self::SUCCESS;
    }
}
