<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('minutes_before');

            // Delivery is tracked per reminder, not per event: "a day before"
            // and "fifteen minutes before" fire at different moments and have to
            // remember separately. sent_for carries the occurrence for a
            // repeating event, sent_at the one-off case.
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('sent_for')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'minutes_before']);
        });

        $this->migrateExistingReminders();

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['reminder_minutes', 'reminder_sent_at', 'reminder_sent_for']);
        });

        Schema::table('calendars', function (Blueprint $table) {
            // Minutes-before values new events on this calendar start with.
            $table->json('default_reminder_minutes')->nullable()->after('is_writable');
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropColumn('default_reminder_minutes');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('reminder_minutes')->nullable()->after('rrule');
            $table->timestamp('reminder_sent_at')->nullable()->after('reminder_minutes');
            $table->timestamp('reminder_sent_for')->nullable()->after('reminder_sent_at');
        });

        Schema::dropIfExists('event_reminders');
    }

    /**
     * Carry the single-column reminders over, delivery state included, so an
     * event that had already reminded does not remind again.
     */
    private function migrateExistingReminders(): void
    {
        $now = now();

        DB::table('events')
            ->whereNotNull('reminder_minutes')
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($now) {
                $rows = [];

                foreach ($events as $event) {
                    $rows[] = [
                        'event_id' => $event->id,
                        'minutes_before' => $event->reminder_minutes,
                        'sent_at' => $event->reminder_sent_at,
                        'sent_for' => $event->reminder_sent_for,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('event_reminders')->insert($rows);
                }
            });
    }
};
