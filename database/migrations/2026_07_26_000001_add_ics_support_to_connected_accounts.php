<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            // Subscribed feeds have no email; only OAuth accounts do.
            $table->string('email_address')->nullable()->change();

            // Public webcal/ICS feed URL, encrypted at rest since a private
            // feed URL is itself a bearer credential.
            $table->text('feed_url')->nullable()->after('email_address');

            // Encrypted values aren't queryable, so dedup on a hash instead.
            $table->string('feed_url_hash', 64)->nullable()->after('feed_url');

            $table->unique(['user_id', 'feed_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'feed_url_hash']);
            $table->dropColumn(['feed_url', 'feed_url_hash']);
            $table->string('email_address')->nullable(false)->change();
        });
    }
};
