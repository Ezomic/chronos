<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The browser's push endpoint, which identifies the device. Long
            // enough that it needs a hash to be indexable.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // The keys the payload is encrypted to, straight from the browser's
            // PushSubscription.
            $table->text('public_key');
            $table->text('auth_token');

            $table->string('device_label')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
