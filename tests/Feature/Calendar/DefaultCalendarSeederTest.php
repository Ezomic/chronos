<?php

use App\Models\Calendar;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('gives every user exactly one default writable calendar and is idempotent', function () {
    User::factory()->create();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    User::query()->each(function (User $user) {
        $defaults = Calendar::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->get();

        expect($defaults)->toHaveCount(1)
            ->and($defaults->first()->is_writable)->toBeTrue();
    });
});
