<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            // Publishing is opt-in. A token present means the calendar is
            // readable at its feed URL by anyone holding it, which is why it is
            // long, random, rotatable, and unique across every calendar.
            $table->string('publish_token', 64)->nullable()->unique()->after('default_reminder_minutes');
            $table->timestamp('published_at')->nullable()->after('publish_token');
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropUnique(['publish_token']);
            $table->dropColumn(['publish_token', 'published_at']);
        });
    }
};
