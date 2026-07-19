<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CreateDefaultCalendarAction;
use App\Models\User;

class UserObserver
{
    public function __construct(private readonly CreateDefaultCalendarAction $createDefaultCalendar) {}

    public function created(User $user): void
    {
        $this->createDefaultCalendar->handle($user);
    }
}
