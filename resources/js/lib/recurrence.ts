export type Frequency = 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly';
export type MonthlyMode = 'day_of_month' | 'weekday';
export type Ends = 'never' | 'until' | 'count';

export interface RecurrenceForm {
    frequency: Frequency;
    interval: number;
    byday: string[];
    monthly_mode: MonthlyMode;
    ends: Ends;
    until: string;
    count: number;
}

// Monday first, matching the calendar grid.
export const WEEKDAYS: { value: string; label: string; long: string }[] = [
    { value: 'MO', label: 'Mon', long: 'Monday' },
    { value: 'TU', label: 'Tue', long: 'Tuesday' },
    { value: 'WE', label: 'Wed', long: 'Wednesday' },
    { value: 'TH', label: 'Thu', long: 'Thursday' },
    { value: 'FR', label: 'Fri', long: 'Friday' },
    { value: 'SA', label: 'Sat', long: 'Saturday' },
    { value: 'SU', label: 'Sun', long: 'Sunday' },
];

const FREQUENCIES: Record<string, Frequency> = {
    DAILY: 'daily',
    WEEKLY: 'weekly',
    MONTHLY: 'monthly',
    YEARLY: 'yearly',
};

const UNITS: Record<Frequency, [string, string]> = {
    none: ['', ''],
    daily: ['day', 'days'],
    weekly: ['week', 'weeks'],
    monthly: ['month', 'months'],
    yearly: ['year', 'years'],
};

const POSITIONS = ['first', 'second', 'third', 'fourth', 'last'];

export function emptyRecurrence(): RecurrenceForm {
    return {
        frequency: 'none',
        interval: 1,
        byday: [],
        monthly_mode: 'day_of_month',
        ends: 'never',
        until: '',
        count: 10,
    };
}

export function parseRrule(rrule: string | null): RecurrenceForm {
    const form = emptyRecurrence();

    if (!rrule) {
        return form;
    }

    const freq = rrule.match(/FREQ=(\w+)/);
    form.frequency = (freq && FREQUENCIES[freq[1]]) || 'none';

    const interval = rrule.match(/INTERVAL=(\d+)/);
    form.interval = interval ? Number(interval[1]) : 1;

    const byday = rrule.match(/BYDAY=([^;]+)/);

    if (byday) {
        // A monthly rule's BYDAY carries a position ("2TH", "-1WE"); a weekly
        // one is a plain list of days.
        if (form.frequency === 'monthly') {
            form.monthly_mode = 'weekday';
        } else {
            form.byday = byday[1].split(',');
        }
    }

    const count = rrule.match(/COUNT=(\d+)/);
    const until = rrule.match(/UNTIL=(\d{4})(\d{2})(\d{2})/);

    if (count) {
        form.ends = 'count';
        form.count = Number(count[1]);
    } else if (until) {
        form.ends = 'until';
        form.until = `${until[1]}-${until[2]}-${until[3]}`;
    }

    return form;
}

/**
 * The rule in words, so what was chosen is legible without reading an RRULE.
 * The start date supplies what a monthly rule repeats on.
 */
export function describeRecurrence(
    form: RecurrenceForm,
    startDate: string,
): string {
    if (form.frequency === 'none') {
        return 'Does not repeat';
    }

    const [singular, plural] = UNITS[form.frequency];
    const every =
        form.interval > 1
            ? `Every ${form.interval} ${plural}`
            : `Every ${singular}`;

    return `${every}${detail(form, startDate)}${ending(form)}`;
}

function detail(form: RecurrenceForm, startDate: string): string {
    if (form.frequency === 'weekly' && form.byday.length > 0) {
        const names = WEEKDAYS.filter((d) => form.byday.includes(d.value)).map(
            (d) => d.long,
        );

        return ` on ${list(names)}`;
    }

    if (form.frequency !== 'monthly') {
        return '';
    }

    const date = parseDate(startDate);

    if (!date) {
        return '';
    }

    if (form.monthly_mode === 'weekday') {
        const position =
            POSITIONS[Math.min(Math.ceil(date.getDate() / 7), 5) - 1];
        const weekday = WEEKDAYS[(date.getDay() + 6) % 7].long;

        return ` on the ${position} ${weekday}`;
    }

    return ` on the ${ordinal(date.getDate())}`;
}

function ending(form: RecurrenceForm): string {
    if (form.ends === 'count') {
        return `, ${form.count} ${form.count === 1 ? 'time' : 'times'}`;
    }

    if (form.ends === 'until' && form.until) {
        const date = parseDate(form.until);

        return date
            ? `, until ${date.toLocaleDateString(undefined, {
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
              })}`
            : '';
    }

    return '';
}

function parseDate(value: string): Date | null {
    const parts = value.slice(0, 10).split('-').map(Number);

    if (parts.length !== 3 || parts.some(Number.isNaN)) {
        return null;
    }

    // Local, not UTC: these are wall-clock dates, and a UTC parse can land on
    // the previous day west of Greenwich.
    return new Date(parts[0], parts[1] - 1, parts[2]);
}

function ordinal(day: number): string {
    const remainder = day % 100;

    if (remainder >= 11 && remainder <= 13) {
        return `${day}th`;
    }

    return `${day}${['th', 'st', 'nd', 'rd'][day % 10] ?? 'th'}`;
}

function list(items: string[]): string {
    if (items.length <= 1) {
        return items[0] ?? '';
    }

    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}
