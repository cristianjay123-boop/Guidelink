<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_cursors', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_read_through_id')->default(0);
            $table->unsignedBigInteger('post_read_through_id')->default(0);
            $table->timestamps();
        });

        $messageReadThroughId = (int) (DB::table('direct_messages')->max('id') ?? 0);
        $postReadThroughId = (int) (DB::table('guidance_announcements')->max('id') ?? 0);
        $now = now();

        DB::table('users')->orderBy('id')->chunk(500, function ($users) use (
            $messageReadThroughId,
            $postReadThroughId,
            $now,
        ): void {
            DB::table('user_notification_cursors')->insert(
                $users->map(fn (object $user) => [
                    'user_id' => $user->id,
                    'message_read_through_id' => $messageReadThroughId,
                    'post_read_through_id' => $postReadThroughId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_cursors');
    }
};
