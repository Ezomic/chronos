<?php

namespace Database\Seeders;

use App\Actions\CreateDefaultCalendarAction;
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

        // Model events are suppressed during seeding, so the UserObserver that
        // normally provisions a default calendar doesn't fire — do it here for
        // every user (idempotent).
        $action = app(CreateDefaultCalendarAction::class);

        User::query()->each(fn (User $user) => $action->handle($user));
    }
}
