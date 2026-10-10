<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->unique(
                ['evaluation_form_id', 'student_id', 'evaluated_teacher_id'],
                'evaluation_responses_form_student_teacher_unique',
            );
            $table->dropUnique('evaluation_responses_evaluation_form_id_student_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->unique(['evaluation_form_id', 'student_id']);
            $table->dropUnique('evaluation_responses_form_student_teacher_unique');
        });
    }
};
