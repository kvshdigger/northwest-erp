<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases_orders', function (Blueprint $table): void {
            $table->foreignId('work_order_id')->nullable()->constrained('v2_work_orders')->restrictOnDelete();
        });
        Schema::table('v2_work_order_allocations', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('purchases_orders')->restrictOnDelete();
            $table->foreign('order_line_id')->references('id')->on('purchases_order_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v2_work_order_allocations', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['order_line_id']);
        });
        Schema::table('purchases_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_order_id');
        });
    }
};
