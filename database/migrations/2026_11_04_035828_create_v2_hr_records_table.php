<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_hr_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('kind', 30);
            $table->string('state', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due_on')->nullable()->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->longText('data');
            $table->timestamps();
            $table->index(['company_id', 'kind', 'state'], 'v2_hr_company_kind');
            $table->index(['employee_id', 'kind', 'starts_at', 'ends_at'], 'v2_hr_intervals');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_hr_records');
    }
};
