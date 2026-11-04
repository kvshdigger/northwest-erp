<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('partner_id')->constrained('partners_partners')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('reference', 120);
            $table->string('state', 20)->default('active');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reference']);
        });
        Schema::create('v2_work_order_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('v2_work_orders')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('revision')->default(1);
            $table->string('state', 20)->default('draft');
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->longText('data');
            $table->timestamps();
            $table->unique(['work_order_id', 'number']);
        });
        Schema::create('v2_work_order_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('v2_work_order_versions')->restrictOnDelete();
            $table->unsignedBigInteger('route_id')->index();
            $table->uuid('source_key');
            $table->decimal('chainage_from', 15, 3);
            $table->decimal('chainage_to', 15, 3);
            $table->timestamps();
            $table->unique(['version_id', 'source_key']);
        });
        Schema::create('v2_work_order_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('v2_work_orders')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('v2_work_order_versions')->restrictOnDelete();
            $table->uuid('source_key');
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_line_id')->unique();
            $table->decimal('quantity', 18, 4);
            $table->decimal('maincon_rate', 18, 4);
            $table->decimal('subcon_rate', 18, 4);
            $table->timestamps();
            $table->index(['work_order_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_work_order_allocations');
        Schema::dropIfExists('v2_work_order_routes');
        Schema::dropIfExists('v2_work_order_versions');
        Schema::dropIfExists('v2_work_orders');
    }
};
