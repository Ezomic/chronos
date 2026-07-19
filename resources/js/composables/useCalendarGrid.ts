import {
    parseAbsolute,
    parseDate,
    startOfWeek,
    toCalendarDate,
} from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { computed, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import type { CalendarEvent } from '@/types/calendar';

// Dutch locale: weeks start on Monday.
const LOCALE = 'nl-NL';
const GRID_DAYS = 42;

export interface DayCell {
    date: CalendarDate;
    key: string;
    day: number;
    inCurrentMonth: boolean;
    isToday: boolean;
    events: CalendarEvent[];
}

function dayKey(date: CalendarDate): string {
    return date.toString();
}

/**
 * The inclusive span of local dates an event covers. Both endpoints are
 * resolved in the event's own timezone; all-day ends are exclusive, so the
 * last covered day is the day before ends_at.
 */
function eventSpan(event: CalendarEvent): {
    start: CalendarDate;
    end: CalendarDate;
} {
    const start = toCalendarDate(
        parseAbsolute(event.starts_at, event.timezone),
    );
    let end = toCalendarDate(parseAbsolute(event.ends_at, event.timezone));

    if (event.all_day) {
        end = end.subtract({ days: 1 });
    }

    if (end.compare(start) < 0) {
        end = start;
    }

    return { start, end };
}

export function formatEventTime(event: CalendarEvent): string {
    if (event.all_day) {
        return '';
    }

    const start = parseAbsolute(event.starts_at, event.timezone);

    return `${start.hour}:${String(start.minute).padStart(2, '0')}`;
}

export function useCalendarGrid(
    anchor: MaybeRefOrGetter<string>,
    events: MaybeRefOrGetter<CalendarEvent[]>,
    todayKey: MaybeRefOrGetter<string>,
) {
    const monthStart = computed(() => {
        const date = parseDate(toValue(anchor));

        return date.set({ day: 1 });
    });

    const gridStart = computed(() => startOfWeek(monthStart.value, LOCALE));

    const eventsByDay = computed(() => {
        const map = new Map<string, CalendarEvent[]>();

        for (const event of toValue(events)) {
            const { start, end } = eventSpan(event);

            for (
                let cursor = start;
                cursor.compare(end) <= 0;
                cursor = cursor.add({ days: 1 })
            ) {
                const key = dayKey(cursor);
                const bucket = map.get(key);

                if (bucket) {
                    bucket.push(event);
                } else {
                    map.set(key, [event]);
                }
            }
        }

        return map;
    });

    const cells = computed<DayCell[]>(() => {
        const currentMonth = monthStart.value.month;
        const today = toValue(todayKey);
        const result: DayCell[] = [];

        for (let i = 0; i < GRID_DAYS; i++) {
            const date = gridStart.value.add({ days: i });
            const key = dayKey(date);

            result.push({
                date,
                key,
                day: date.day,
                inCurrentMonth: date.month === currentMonth,
                isToday: key === today,
                events: eventsByDay.value.get(key) ?? [],
            });
        }

        return result;
    });

    const weeks = computed<DayCell[][]>(() => {
        const rows: DayCell[][] = [];

        for (let i = 0; i < cells.value.length; i += 7) {
            rows.push(cells.value.slice(i, i + 7));
        }

        return rows;
    });

    return { weeks };
}
