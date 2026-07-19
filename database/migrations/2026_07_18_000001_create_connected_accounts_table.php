<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // google | microsoft
            $table->string('provider');
            $table->string('email_address');
            $table->string('display_name')->nullable();

            // Read-only calendar access tokens, encrypted at rest.
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();

            $table->string('sync_status')->default('idle'); // idle | syncing | error
            $table->timestamp('sync_status_since')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'provider', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
