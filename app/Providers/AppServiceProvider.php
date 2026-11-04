<?php

namespace App\Providers;

use App\Services\WorkOrderPurchasing;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Security\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Authenticatable::class, User::class);
        $this->app->singleton(WorkOrderPurchasing::class);
    }

    public function boot(): void
    {
        foreach ([Order::class, PurchaseOrder::class] as $class) {
            $class::saving(fn ($order) => app(WorkOrderPurchasing::class)->guardOrder($order));
        }
        OrderLine::saving(fn ($line) => app(WorkOrderPurchasing::class)->guardLine($line));
        OrderLine::deleting(fn ($line) => app(WorkOrderPurchasing::class)->guardLine($line, true));
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
