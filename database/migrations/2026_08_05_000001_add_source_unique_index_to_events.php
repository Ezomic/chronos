<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeExistingDuplicates();

        Schema::table('events', function (Blueprint $table) {
            // One event per source row. NULL source columns stay distinct, so
            // locally created events are unaffected.
            $table->unique(
                ['calendar_id', 'source_app', 'source_type', 'source_id'],
                'events_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_source_unique');
        });
    }

    /**
     * Events created before the API deduplicated: the same source row posted
     * twice produced two rows. The index cannot be added while they exist, so
     * the earliest of each group is kept and its copies dropped.
     */
    private function removeExistingDuplicates(): void
    {
        $groups = DB::table('events')
            ->selectRaw('calendar_id, source_app, source_type, source_id, MIN(id) as keep_id, COUNT(*) as total')
            ->whereNotNull('source_app')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('calendar_id', 'source_app', 'source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            DB::table('events')
                ->where('calendar_id', $group->calendar_id)
                ->where('source_app', $group->source_app)
                ->where('source_type', $group->source_type)
                ->where('source_id', $group->source_id)
                ->where('id', '!=', $group->keep_id)
                ->delete();
        }
    }
};
