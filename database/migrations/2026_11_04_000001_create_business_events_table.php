<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_business_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 30);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 20);
            $table->uuid('request_id')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->text('changes');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'request_id'], 'v2_business_request_unique');
            $table->index(['kind', 'record_id'], 'v2_business_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_business_events');
    }
};
