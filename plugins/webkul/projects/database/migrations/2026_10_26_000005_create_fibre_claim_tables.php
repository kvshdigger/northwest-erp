<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_fibre_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->foreignId('work_package_id')->constrained('projects_fibre_work_packages')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('claim_reference')->unique();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status'], 'fibre_claim_project_status_idx');
            $table->index(['work_package_id', 'status'], 'fibre_claim_package_status_idx');
        });

        Schema::create('projects_fibre_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('projects_fibre_claims')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('work_package_item_id')->constrained('projects_fibre_work_package_items')->restrictOnDelete();
            $table->decimal('quantity_claimed', 14, 3);
            $table->decimal('subcontractor_rate_snapshot', 14, 2);
            $table->decimal('amount', 16, 2);
            $table->timestamps();
            $table->unique(['claim_id', 'work_package_item_id'], 'fibre_claim_item_unique');
        });

        Schema::create('projects_fibre_claim_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_item_id')->constrained('projects_fibre_claim_items')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('quantity_paid', 14, 3);
            $table->decimal('amount_paid', 16, 2);
            $table->date('paid_on');
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['claim_item_id', 'paid_on'], 'fibre_claim_payment_item_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_fibre_claim_payments');
        Schema::dropIfExists('projects_fibre_claim_items');
        Schema::dropIfExists('projects_fibre_claims');
    }
};