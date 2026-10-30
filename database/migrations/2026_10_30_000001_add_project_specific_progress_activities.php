<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_progress_reports', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects_projects')->nullOnDelete();
            $table->index(['project_id', 'project_route', 'report_date'], 'daily_progress_project_route_date_idx');
        });

        Schema::create('project_progress_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 20)->default('m');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_progress_activities');

        Schema::table('daily_progress_reports', function (Blueprint $table): void {
            $table->dropIndex('daily_progress_project_route_date_idx');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
