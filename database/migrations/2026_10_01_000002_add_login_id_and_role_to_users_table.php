<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login_id')->nullable()->unique()->after('id');
            $table->string('role')->default(UserRole::Student->value)->after('login_id');
        });

        DB::table('users')
            ->whereNull('login_id')
            ->whereNotNull('student_number')
            ->update(['login_id' => DB::raw('student_number')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login_id']);
            $table->dropColumn(['login_id', 'role']);
        });
    }
};
