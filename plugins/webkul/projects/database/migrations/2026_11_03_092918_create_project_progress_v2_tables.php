<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_progress_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_submit')->default(true);
            $table->boolean('can_configure')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
        Schema::create('projects_progress_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_key', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'name_key']);
        });
        Schema::create('projects_progress_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->json('draft')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('projects_progress_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained('projects_progress_activities')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['activity_id', 'number']);
        });
        Schema::create('projects_progress_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->foreignId('route_id')->constrained('projects_progress_routes')->restrictOnDelete();
            $table->date('report_date');
            $table->string('team', 120);
            $table->string('location')->nullable();
            $table->uuid('submission_key');
            $table->string('payload_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'submission_key']);
            $table->index(['project_id', 'route_id', 'report_date'], 'project_progress_daily_idx');
        });
        Schema::create('projects_progress_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('projects_progress_reports')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('projects_progress_versions')->restrictOnDelete();
            $table->json('inputs');
            $table->string('result_type', 20);
            $table->decimal('result_numeric', 24, 6)->nullable();
            $table->text('result_text')->nullable();
            $table->string('team', 120);
            $table->string('location')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('projects_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects_projects')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_progress_events');
        Schema::dropIfExists('projects_progress_entries');
        Schema::dropIfExists('projects_progress_reports');
        Schema::dropIfExists('projects_progress_versions');
        Schema::dropIfExists('projects_progress_activities');
        Schema::dropIfExists('projects_progress_routes');
        Schema::dropIfExists('projects_progress_memberships');
    }
};
