<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            // The subscriber's local zone, captured when an ICS feed is added,
            // so its UTC-published times display in local time.
            $table->string('timezone')->nullable()->after('feed_url_hash');
        });
    }

    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
