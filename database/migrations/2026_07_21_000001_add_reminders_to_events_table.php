<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Minutes before starts_at to remind the owner; null = no reminder.
            $table->unsignedInteger('reminder_minutes')->nullable()->after('rrule');
            // When the reminder was dispatched, so it fires at most once.
            $table->timestamp('reminder_sent_at')->nullable()->after('reminder_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['reminder_minutes', 'reminder_sent_at']);
        });
    }
};
