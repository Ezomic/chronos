<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // An occurrence the user changed, stored as its own event. It has no
            // rrule of its own; the expander yields it in place of the
            // occurrence it names, which is what overrides_starts_at records.
            $table->foreignId('overrides_event_id')
                ->nullable()
                ->after('rrule')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->timestamp('overrides_starts_at')->nullable()->after('overrides_event_id');

            // Occurrence starts the series no longer produces, the RRULE EXDATE
            // idea. Stored on the master as UTC 'Y-m-d H:i:s' strings.
            $table->json('excluded_dates')->nullable()->after('overrides_starts_at');

            // One override per occurrence.
            $table->unique(['overrides_event_id', 'overrides_starts_at'], 'events_override_unique');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_override_unique');
            $table->dropForeign(['overrides_event_id']);
            $table->dropColumn(['overrides_event_id', 'overrides_starts_at', 'excluded_dates']);
        });
    }
};
