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
 * All times are handled in UTC (that's how events are stored); the master's
 * starts_at is the series anchor and its duration is applied to each occurrence.
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

        $rule = new Rule(
            $event->rrule,
            $event->starts_at->toDateTime(),
            $event->ends_at->toDateTime(),
            'UTC',
        );

        $config = new ArrayTransformerConfig;
        $config->enableLastDayOfMonthFix();

        $recurrences = (new ArrayTransformer($config))->transform(
            $rule,
            new BetweenConstraint($from->toDateTime(), $to->toDateTime(), true),
        );

        return array_map(fn ($occurrence): array => [
            'starts_at' => CarbonImmutable::instance($occurrence->getStart()),
            'ends_at' => CarbonImmutable::instance($occurrence->getEnd()),
        ], $recurrences->toArray());
    }
}
