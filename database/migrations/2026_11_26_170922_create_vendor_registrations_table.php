<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('state')->default('invited');
            $t->text('data')->nullable();
            $t->timestamp('expires_at');
            $t->unsignedBigInteger('creator_id');
            $t->unsignedBigInteger('reviewer_id')->nullable();
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_registrations');
    }
};
