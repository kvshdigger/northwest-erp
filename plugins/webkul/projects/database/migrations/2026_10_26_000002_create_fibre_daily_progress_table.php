<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_fibre_daily_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->foreignId('route_section_id')->constrained('projects_fibre_route_sections')->restrictOnDelete();
            $table->foreignId('work_package_id')->constrained('projects_fibre_work_packages')->restrictOnDelete();
            $table->foreignId('work_package_item_id')->constrained('projects_fibre_work_package_items')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('start_chainage_m', 14, 3)->nullable();
            $table->decimal('end_chainage_m', 14, 3)->nullable();
            $table->decimal('reported_quantity', 14, 3);
            $table->string('status')->default('draft');
            $table->json('evidence_paths');
            $table->text('photo_exception_reason')->nullable();
            $table->text('work_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'work_date', 'status'], 'fibre_progress_project_date_status_idx');
            $table->index(['work_package_id', 'work_date'], 'fibre_progress_package_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_fibre_daily_progress');
    }
};
