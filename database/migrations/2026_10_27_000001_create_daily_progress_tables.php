<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_progress_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date')->index();
            $table->string('project_route');
            $table->string('section_name')->nullable();
            $table->string('section_start_chainage', 30)->nullable();
            $table->string('section_end_chainage', 30)->nullable();
            $table->decimal('section_start_chainage_m', 12, 3)->nullable();
            $table->decimal('section_end_chainage_m', 12, 3)->nullable();
            $table->string('team_or_subcontractor')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_route', 'report_date']);
        });

        Schema::create('daily_progress_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_progress_report_id')->constrained('daily_progress_reports')->cascadeOnDelete();
            $table->string('activity');
            $table->string('unit', 20);
            $table->string('start_chainage', 30);
            $table->string('end_chainage', 30);
            $table->decimal('start_chainage_m', 12, 3);
            $table->decimal('end_chainage_m', 12, 3);
            $table->decimal('chainage_length_m', 12, 3)->default(0);
            $table->decimal('planned_quantity', 12, 3)->nullable();
            $table->decimal('daily_quantity', 12, 3);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['activity', 'start_chainage_m', 'end_chainage_m'], 'progress_entries_chainage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_progress_entries');
        Schema::dropIfExists('daily_progress_reports');
    }
};
