<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_source_unique');
        });

        // The CHRON-48 guarantee is about live events: one per source row. A
        // deleted event still occupies its slot under a plain unique index, so
        // a consuming app re-creating something the user deleted would hit a
        // constraint violation instead of creating it. A partial index scopes
        // the rule to rows that still exist. Expressed as a statement because
        // the schema builder has no portable partial index, which is fine here:
        // Chronos is SQLite and says so.
        DB::statement(
            'CREATE UNIQUE INDEX events_source_unique ON events '.
            '(calendar_id, source_app, source_type, source_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS events_source_unique');

        Schema::table('events', function (Blueprint $table) {
            $table->unique(['calendar_id', 'source_app', 'source_type', 'source_id'], 'events_source_unique');
            $table->dropSoftDeletes();
        });
    }
};
