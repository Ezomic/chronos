<?php

declare(strict_types=1);

/**
 * The apps allowed to create events over the hub API, and the label the
 * calendar uses for their "open the thing this came from" link.
 *
 * Configured as a comma-separated list of `slug:Label` pairs so onboarding a
 * consumer is an environment change rather than a code change and a deploy.
 */
$consumers = static function (): array {
    $raw = env('CHRONOS_CONSUMER_APPS', 'zero:Open in Mail,tracker:Open in Tracker,tempo:Open in Tempo');
    $consumers = [];

    foreach (explode(',', is_string($raw) ? $raw : '') as $entry) {
        [$slug, $label] = array_pad(explode(':', trim($entry), 2), 2, null);

        $slug = trim((string) $slug);

        if ($slug === '') {
            continue;
        }

        $label = trim((string) ($label ?? ''));

        $consumers[$slug] = $label !== '' ? $label : 'Open in '.ucfirst($slug);
    }

    return $consumers;
};

return [

    'consumers' => $consumers(),

];
