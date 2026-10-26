<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_fibre_progress_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_id')->constrained('projects_fibre_daily_progress')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->decimal('quantity', 14, 3)->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['progress_id', 'created_at'], 'fibre_progress_reviews_history_idx');
            $table->index(['company_id', 'event_type'], 'fibre_progress_reviews_company_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_fibre_progress_reviews');
    }
};
