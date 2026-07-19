<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * A link from an event back to the row in another app it was created from
 * (e.g. a zero email). Not a Laravel morph: the target lives in a different
 * database, so this is carried as four plain fields.
 */
final readonly class EventSource
{
    public function __construct(
        public string $app,
        public string $type,
        public string $id,
        public string $url,
    ) {}
}
