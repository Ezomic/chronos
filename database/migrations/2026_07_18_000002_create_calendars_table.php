<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // null = a local calendar Chronos owns; otherwise a mirror of one
            // calendar on a connected external account.
            $table->foreignId('connected_account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 7)->default('#5B5BD6');
            $table->string('timezone')->default('Europe/Amsterdam');

            // Provider calendar id for mirrored calendars.
            $table->string('external_id')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_visible')->default(true);
            // Only local calendars are writable; mirrored ones are read-only.
            $table->boolean('is_writable')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // A connected account maps each external calendar once. Local
            // calendars have (null, null) here, which SQLite treats as distinct,
            // so a user can keep several.
            $table->unique(['connected_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendars');
    }
};
