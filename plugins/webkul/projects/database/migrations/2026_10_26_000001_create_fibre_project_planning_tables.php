<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_fibre_boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('item_code')->nullable()->index();
            $table->string('description');
            $table->string('unit', 24);
            $table->decimal('contract_quantity', 14, 3)->default(0);
            $table->decimal('client_rate', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'item_code']);
        });

        Schema::create('projects_fibre_route_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable()->index();
            $table->string('start_location')->nullable();
            $table->string('end_location')->nullable();
            $table->decimal('start_chainage_m', 14, 3)->nullable();
            $table->decimal('end_chainage_m', 14, 3)->nullable();
            $table->string('status')->default('planned');
            $table->text('access_notes')->nullable();
            $table->text('risk_notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::create('projects_fibre_work_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->foreignId('route_section_id')->constrained('projects_fibre_route_sections')->cascadeOnDelete();
            $table->foreignId('subcontractor_id')->constrained('partners_partners')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('reference')->nullable()->index();
            $table->decimal('start_chainage_m', 14, 3)->nullable();
            $table->decimal('end_chainage_m', 14, 3)->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('scope_notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'subcontractor_id', 'status'], 'fibre_packages_project_subcon_status_idx');
        });

        Schema::create('projects_fibre_work_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_package_id')->constrained('projects_fibre_work_packages')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('boq_item_id')->constrained('projects_fibre_boq_items')->restrictOnDelete();
            $table->decimal('allocated_quantity', 14, 3)->default(0);
            $table->decimal('subcontractor_rate', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['work_package_id', 'boq_item_id'], 'fibre_package_boq_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_fibre_work_package_items');
        Schema::dropIfExists('projects_fibre_work_packages');
        Schema::dropIfExists('projects_fibre_route_sections');
        Schema::dropIfExists('projects_fibre_boq_items');
    }
};
