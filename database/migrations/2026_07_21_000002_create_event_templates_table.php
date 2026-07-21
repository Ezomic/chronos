<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The calendar events made from this template land on. Nullable so a
            // template outlives a deleted calendar; the picker falls back then.
            $table->foreignId('calendar_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            $table->boolean('all_day')->default(false);
            // Timed: length in minutes. All-day: days x 1440.
            $table->unsignedInteger('duration_minutes');
            // "HH:MM" the new-event form defaults its start to; null = no default.
            $table->string('default_start_time', 5)->nullable();

            // Repeat pattern (no absolute dates, no UNTIL); null = does not repeat.
            $table->string('frequency')->nullable();
            // Minutes before start to remind; null = no reminder.
            $table->unsignedInteger('reminder_minutes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_templates');
    }
};
