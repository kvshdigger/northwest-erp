<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_progress_entries', function (Blueprint $table): void {
            $table->string('start_chainage', 30)->nullable()->change();
            $table->string('end_chainage', 30)->nullable()->change();
            $table->decimal('start_chainage_m', 12, 3)->nullable()->change();
            $table->decimal('end_chainage_m', 12, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve non-chainage entries; reverting to NOT NULL would destroy their validity.
    }
};
