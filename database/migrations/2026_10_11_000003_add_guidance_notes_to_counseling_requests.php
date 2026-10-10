<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counseling_requests', function (Blueprint $table) {
            $table->text('guidance_notes')->nullable()->after('decline_reason');
        });
    }

    public function down(): void
    {
        Schema::table('counseling_requests', function (Blueprint $table) {
            $table->dropColumn('guidance_notes');
        });
    }
};
