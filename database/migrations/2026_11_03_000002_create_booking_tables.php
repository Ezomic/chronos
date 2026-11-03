<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->unsignedSmallInteger('duration_minutes');
            // Slots start on this grid, so a 20 minute call is offered on the half hour.
            $table->unsignedSmallInteger('slot_step_minutes');
            $table->unsignedSmallInteger('buffer_minutes');
            $table->unsignedInteger('min_notice_minutes');
            $table->unsignedSmallInteger('max_days_ahead');
            // The zone the weekly hours are written in.
            $table->string('timezone');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            // ISO weekday: 1 is Monday, 7 is Sunday.
            $table->unsignedTinyInteger('weekday');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->timestamps();

            $table->index(['booking_type_id', 'weekday']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            // What the public links carry, so ids are never guessable.
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            // The event goes with the booking when it is purged for good.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_note')->nullable();
            $table->string('guest_timezone');
            $table->string('locale', 5);

            // "{type id}:{start}" while the booking is live, null once cancelled.
            // The unique index is the guard against two visitors taking the same
            // slot at once; a unique index lets any number of nulls through.
            $table->string('slot_key')->nullable()->unique();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('availability_windows');
        Schema::dropIfExists('booking_types');
    }
};
