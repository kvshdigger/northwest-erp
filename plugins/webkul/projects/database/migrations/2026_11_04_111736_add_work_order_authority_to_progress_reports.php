<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_progress_reports', function (Blueprint $table): void {
            $table->foreignId('work_order_route_id')->nullable()->constrained('v2_work_order_routes')->restrictOnDelete();
            $table->decimal('chainage_from', 15, 3)->nullable();
            $table->decimal('chainage_to', 15, 3)->nullable();
        });
        Schema::table('v2_work_orders', function (Blueprint $table): void {
            $table->foreign('project_id')->references('id')->on('projects_projects')->restrictOnDelete();
        });
        Schema::table('v2_work_order_routes', function (Blueprint $table): void {
            $table->foreign('route_id')->references('id')->on('projects_progress_routes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v2_work_order_routes', function (Blueprint $table): void {
            $table->dropForeign(['route_id']);
        });
        Schema::table('v2_work_orders', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
        });
        Schema::table('projects_progress_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_order_route_id');
            $table->dropColumn(['chainage_from', 'chainage_to']);
        });
    }
};
