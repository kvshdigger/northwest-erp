<?php

namespace App\Console\Commands;

use App\Models\FinanceConfiguration;
use App\Services\NativeFinance;
use App\Services\NativeHr;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Models\Bill;
use Webkul\Accounting\Models\Invoice;
use Webkul\Accounting\Models\Payment;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\Partner;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

class EnableV2Finance extends Command
{
    protected $signature = 'v2:enable-finance {--email= : Existing user to receive Finance view/create/update access} {--company= : Company name to associate with this user}';

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
        if ($email = $this->option('email')) {
            $user = User::where('email', $email)->where('is_active', true)->firstOrFail();
            foreach (['purchase_purchase::order', 'purchase_vendor', 'accounting_invoice', 'accounting_bill', 'accounting_payment', 'product_product'] as $resource) {
                foreach (['view_any', 'view', 'create', 'update'] as $action) {
                    $user->givePermissionTo(Permission::findOrCreate($action.'_'.$resource, 'web'));
                }
            }
            $user->givePermissionTo(Permission::findOrCreate('v2_finance_configure', 'web'));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->refresh();
            Auth::login($user);
            if ($requestedCompany = $this->option('company')) {
                $companies = Company::query()->get();
                $matches = $companies->filter(function ($company) use ($requestedCompany) {
                    $branding = FinanceConfiguration::where('company_id', $company->id)->first()?->settings;

                    return str_contains(Str::lower($company->name), Str::lower($requestedCompany)) || str_contains(Str::lower($branding['display_name'] ?? ''), Str::lower($requestedCompany));
                });
                if ($matches->count() === 1) {
                    $company = $matches->first();
                } elseif ($matches->isEmpty() && $companies->count() === 1) {
                    $company = $companies->first();
                    $this->warn('Using the sole existing company; no company was created or renamed.');
                } else {
                    throw new \RuntimeException('Company selection is ambiguous; no membership was changed.');
                }
                $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
                $user->default_company_id = $company->id;
                $user->save();
                session()->put(CompanyContext::SESSION_KEY, [$company->id]);
                $this->info('Assigned requested user to company: '.$company->name.' (ID '.$company->id.').');
            }
            if (! $user->allowedCompanies()->exists() && $user->default_company_id) {
                $company = Company::findOrFail($user->default_company_id);
                $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
                $this->info('Restored missing company membership for the existing default company only.');
            }
            app()->forgetInstance(CompanyContext::class);
            $companyIds = app(NativeHr::class)->companyIds($user);
            if (! $companyIds) {
                throw new \RuntimeException('No permitted active company is configured for the requested user.');
            }
            foreach (['purchase-orders', 'invoices', 'bills', 'payments', 'items'] as $kind) {
                app(NativeFinance::class)->form($user, $kind, $companyIds[0]);
                $this->info('Verified Finance form: '.$kind);
            }

            foreach ([PurchaseOrder::class, Invoice::class, Bill::class, Payment::class, Product::class, Partner::class] as $model) {
                foreach (['viewAny', 'create'] as $ability) {
                    if (! Gate::forUser($user)->allows($ability, $model)) {
                        throw new \RuntimeException('Finance permission verification failed for '.$model.' '.$ability);
                    }
                }
            }
            $this->info('Requested user Finance permissions granted and policy checks passed. Company memberships unchanged.');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Package::refreshPluginCaches();
        $this->info('V2 Finance activated. Existing records and numbering preserved. Configure accounting defaults if empty.');

        return self::SUCCESS;
    }
}
