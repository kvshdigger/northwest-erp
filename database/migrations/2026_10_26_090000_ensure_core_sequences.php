<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\Schema;
use Webkul\\Account\\Database\\Seeders\\SequenceSeeder as AccountSequenceSeeder;
use Webkul\\Inventory\\Database\\Seeders\\SequenceSeeder as InventorySequenceSeeder;
use Webkul\\Manufacturing\\Database\\Seeders\\SequenceSeeder as ManufacturingSequenceSeeder;
use Webkul\\Purchase\\Database\\Seeders\\SequenceSeeder as PurchaseSequenceSeeder;
use Webkul\\Sale\\Database\\Seeders\\SequenceSeeder as SalesSequenceSeeder;

return new class extends Migration
{
    /**
     * Ensure built-in document sequences exist without resetting existing counters.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sequences')) {
            return;
        }

        if (Schema::hasTable('accounts_journals') && Schema::hasTable('accounts_moves')) {
            (new AccountSequenceSeeder)->run();
        }

        if (Schema::hasTable('inventories_operation_types') && Schema::hasTable('inventories_scraps')) {
            (new InventorySequenceSeeder)->run();
        }

        if (Schema::hasTable('manufacturing_orders')) {
            (new ManufacturingSequenceSeeder)->run();
        }

        if (Schema::hasTable('purchases_orders')) {
            (new PurchaseSequenceSeeder)->run();
        }

        if (Schema::hasTable('sales_orders')) {
            (new SalesSequenceSeeder)->run();
        }
    }

    public function down(): void
    {
        // Intentionally keep sequence records and counters on rollback.
    }
};
