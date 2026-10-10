<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_subject_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('semester', 80);
            $table->string('academic_year', 32);
            $table->string('course_code', 80)->default('');
            $table->string('descriptive_title', 255);
            $table->string('course_year', 80)->default('');
            $table->decimal('units', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(
                ['teacher_id', 'semester', 'academic_year', 'course_code', 'descriptive_title'],
                'teacher_load_unique',
            );
            $table->index(['semester', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_loads');
    }
};
