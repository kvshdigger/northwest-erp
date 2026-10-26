<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_fibre_progress_pos', function (Blueprint $table) {
            $table->foreignId('native_order_id')
                ->nullable()
                ->unique()
                ->after('company_id')
                ->constrained('purchases_orders')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects_fibre_progress_pos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('native_order_id');
        });
    }
};