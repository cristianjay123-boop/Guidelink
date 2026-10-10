<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guidance_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guidance_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('title', 180);
            $table->text('body');
            $table->dateTime('event_at')->nullable();
            $table->string('image_path');
            $table->timestamps();
            $table->index(['type', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guidance_announcements');
    }
};
