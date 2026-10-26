<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_fibre_progress_pos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->foreignId('work_package_id')->constrained('projects_fibre_work_packages')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('po_reference')->nullable()->unique();
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status'], 'fibre_progress_po_project_status_idx');
            $table->index(['work_package_id', 'status'], 'fibre_progress_po_package_status_idx');
        });

        Schema::create('projects_fibre_progress_po_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_po_id')->constrained('projects_fibre_progress_pos')->restrictOnDelete();
            $table->foreignId('progress_approval_id')->constrained('projects_fibre_progress_approvals')->restrictOnDelete();
            $table->index('progress_approval_id', 'fibre_progress_po_approval_idx');
            $table->foreignId('progress_id')->constrained('projects_fibre_daily_progress')->restrictOnDelete();
            $table->foreignId('work_package_item_id')->constrained('projects_fibre_work_package_items')->restrictOnDelete();
            $table->decimal('approved_quantity', 14, 3);
            $table->decimal('subcontractor_rate_snapshot', 14, 2);
            $table->decimal('line_amount', 16, 2);
            $table->timestamps();
            $table->index(['progress_po_id', 'work_package_item_id'], 'fibre_progress_po_line_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_fibre_progress_po_lines');
        Schema::dropIfExists('projects_fibre_progress_pos');
    }
};