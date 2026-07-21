<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // For recurring events: the occurrence start (UTC) the last reminder
            // was sent for, so each occurrence reminds at most once. Single
            // events keep using reminder_sent_at.
            $table->timestamp('reminder_sent_for')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_for');
        });
    }
};
