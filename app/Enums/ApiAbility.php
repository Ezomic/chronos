<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The abilities routes/api.php checks. A token's app scope is carried as an
 * `app:{slug}` ability too, but it says whose token it is rather than what it
 * may do, so it is not one of these.
 */
enum ApiAbility: string
{
    case EventsCreate = 'events:create';
    case EventsManage = 'events:manage';

    public function description(): string
    {
        return match ($this) {
            self::EventsCreate => 'Create events',
            self::EventsManage => 'List, update and delete the events its app created',
        };
    }
}
