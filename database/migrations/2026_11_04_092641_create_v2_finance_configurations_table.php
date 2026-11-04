<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_business_events', function (Blueprint $table): void {
            $table->longText('changes')->change();
        });
        Schema::create('v2_finance_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->longText('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_finance_configurations');
    }
};
