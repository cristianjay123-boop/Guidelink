<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('user_one_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_one_id')->nullable()->change();
            $table->unsignedBigInteger('user_two_id')->nullable()->change();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['user_one_id', 'user_two_id']);
            $table->string('type', 32)->default('direct')->after('id');
            $table->string('title')->nullable()->after('type');
            $table->foreignId('created_by')->nullable()->after('title')->constrained('users')->nullOnDelete();
            $table->unique(
                ['type', 'user_one_id', 'user_two_id'],
                'conversations_type_user_pair_unique',
            );
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        $conversations = DB::table('conversations')
            ->where('type', 'direct')
            ->get(['id', 'user_one_id', 'user_two_id']);

        foreach ($conversations as $conversation) {
            $now = now();
            DB::table('conversation_participants')->insert([
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_one_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_two_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        $globalConversationId = DB::table('conversations')->insertGetId([
            'type' => 'all_students_guidance',
            'title' => 'All Students + Guidance',
            'user_one_id' => null,
            'user_two_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->whereIn('role', [UserRole::Student->value, UserRole::Guidance->value])
            ->get(['id'])
            ->each(fn (object $user) => DB::table('conversation_participants')->insert([
                'conversation_id' => $globalConversationId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');

        DB::table('conversations')
            ->where('type', '!=', 'direct')
            ->delete();

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['user_one_id', 'user_two_id']);
            $table->dropUnique('conversations_type_user_pair_unique');
            $table->dropIndex(['user_one_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'title']);
            $table->unsignedBigInteger('user_one_id')->nullable(false)->change();
            $table->unsignedBigInteger('user_two_id')->nullable(false)->change();
        });
    }
};
