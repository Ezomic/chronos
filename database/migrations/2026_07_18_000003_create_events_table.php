<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            // Always stored in UTC; ends_at is exclusive. For an all-day event
            // both are midnight UTC (start day .. next day) with timezone 'UTC',
            // and are treated as floating dates the UI never converts.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('timezone')->default('UTC');

            // Reserved for local recurring events; unused in v1 (mirrored
            // recurrences are stored as expanded instances, one row each).
            $table->string('rrule')->nullable();

            // Provider event/instance id + etag for mirrored events.
            $table->string('external_id')->nullable();
            $table->string('external_etag')->nullable();

            // Loose link to a row in another app (e.g. a zero email). Not a
            // Laravel morph: the target lives in a different database.
            $table->string('source_app')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_url', 2048)->nullable();

            $table->timestamps();

            $table->index(['calendar_id', 'starts_at']);
            $table->index('starts_at');
            $table->index('ends_at');
            $table->index(['source_app', 'source_type', 'source_id']);
            $table->unique(['calendar_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
