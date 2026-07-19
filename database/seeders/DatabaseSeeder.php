<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'email_verified_at' => now()],
        );

        // Every user needs one default, writable local calendar for events to
        // land in. Idempotent so re-seeding (or seeding after new users exist)
        // never creates a second default.
        User::query()->each(function (User $user): void {
            Calendar::query()->firstOrCreate(
                ['user_id' => $user->id, 'is_default' => true],
                [
                    'name' => 'Personal',
                    'color' => Calendar::COLOR_PALETTE[0],
                    'timezone' => config('app.timezone'),
                    'is_writable' => true,
                    'is_visible' => true,
                ],
            );
        });
    }
}
