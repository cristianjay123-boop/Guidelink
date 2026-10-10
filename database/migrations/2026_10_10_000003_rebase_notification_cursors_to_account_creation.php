<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($users): void {
                foreach ($users as $user) {
                    $messageReadThroughId = DB::table('direct_messages')
                        ->where('created_at', '<', $user->created_at)
                        ->max('id') ?? 0;
                    $postReadThroughId = DB::table('guidance_announcements')
                        ->where('created_at', '<', $user->created_at)
                        ->max('id') ?? 0;

                    DB::table('user_notification_cursors')
                        ->where('user_id', $user->id)
                        ->update([
                            'message_read_through_id' => $messageReadThroughId,
                            'post_read_through_id' => $postReadThroughId,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
