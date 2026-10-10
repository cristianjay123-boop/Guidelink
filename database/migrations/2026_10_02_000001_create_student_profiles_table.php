<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('personal')->nullable();
            $table->longText('emergency_contact')->nullable();
            $table->longText('education')->nullable();
            $table->longText('health')->nullable();
            $table->longText('interests')->nullable();
            $table->longText('family')->nullable();
            $table->longText('socioeconomic')->nullable();
            $table->longText('psychological_tests')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
