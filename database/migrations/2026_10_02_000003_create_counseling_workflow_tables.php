<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counseling_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guidance_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('semester', 120);
            $table->text('message');
            $table->string('status', 32)->default('pending');
            $table->timestamps();
            $table->index(['student_id', 'semester', 'status']);
            $table->index(['guidance_id', 'semester', 'status']);
        });

        Schema::create('counseling_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guidance_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('counseling_request_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('semester', 120);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
            $table->unique(['guidance_id', 'starts_at']);
            $table->index(['guidance_id', 'semester', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counseling_slots');
        Schema::dropIfExists('counseling_requests');
    }
};
