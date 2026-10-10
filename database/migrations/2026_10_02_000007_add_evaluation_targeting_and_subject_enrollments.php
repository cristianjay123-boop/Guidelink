<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_forms', function (Blueprint $table) {
            $table->string('teacher_scope', 32)->default('specific_subject')->after('category');
        });

        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('evaluated_teacher_id')->nullable()->after('student_id');
        });

        Schema::create('student_subject_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 160);
            $table->timestamps();
            $table->unique(['student_id', 'teacher_id', 'subject']);
            $table->index(['teacher_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subject_enrollments');

        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluated_teacher_id');
        });

        Schema::table('evaluation_forms', function (Blueprint $table) {
            $table->dropColumn('teacher_scope');
        });
    }
};
