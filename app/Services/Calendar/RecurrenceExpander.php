<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;

/**
 * Expands a local event's RRULE into concrete occurrences within a window.
 * The master's starts_at is the series anchor and its duration is applied to
 * each occurrence.
 *
 * Expansion happens in the event's own timezone, not in the UTC it is stored
 * in. A series repeats at a wall-clock time ("Mondays at 09:00"), not at a
 * fixed UTC offset, so expanding in UTC would shift every occurrence by an hour
 * once the zone crosses a DST boundary. Occurrences are converted back to UTC
 * on the way out, matching how single events are stored. All-day events carry
 * timezone 'UTC' and so keep their existing floating behaviour.
 */
class RecurrenceExpander
{
    /**
     * @return array<int, array{starts_at: CarbonInterface, ends_at: CarbonInterface}>
     */
    public function expand(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (blank($event->rrule)) {
            return [['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]];
        }

        $timezone = $event->timezone;

        $rule = new Rule(
            $event->rrule,
            $event->starts_at->setTimezone($timezone)->toDateTime(),
            $event->ends_at->setTimezone($timezone)->toDateTime(),
            $timezone,
        );

        $config = new ArrayTransformerConfig;
        $config->enableLastDayOfMonthFix();

        $recurrences = (new ArrayTransformer($config))->transform(
            $rule,
            new BetweenConstraint($from->toDateTime(), $to->toDateTime(), true),
        );

        $occurrences = [];

        foreach ($recurrences as $occurrence) {
            $occurrences[] = [
                'starts_at' => CarbonImmutable::instance($occurrence->getStart())->utc(),
                'ends_at' => CarbonImmutable::instance($occurrence->getEnd())->utc(),
            ];
        }

        return $occurrences;
    }
}
