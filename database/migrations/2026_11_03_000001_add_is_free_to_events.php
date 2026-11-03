<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Free time does not block a booking slot. Google reports it as
            // "transparent", which is how it marks birthdays and the like.
            $table->boolean('is_free')->default(false)->after('all_day');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });
    }
};
