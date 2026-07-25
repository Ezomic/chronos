export interface CalendarEvent {
    /** Unique per occurrence (a recurring series yields many with the same id). */
    key: string;
    /** The database event id (the master, for recurring series). */
    id: number;
    calendar_id: number;
    /** The event's calendar name (shown for read-only mirrored events). */
    calendar_name: string;
    /** False for events on read-only mirrored calendars (Google/Microsoft). */
    editable: boolean;
    title: string;
    description: string | null;
    color: string;
    all_day: boolean;
    /** ISO 8601 UTC instant (this occurrence). */
    starts_at: string;
    /** ISO 8601 UTC instant, exclusive (this occurrence). */
    ends_at: string;
    /** IANA zone the event was authored in. */
    timezone: string;
    location: string | null;
    /** The app this event was created from, if any (e.g. "zero"). */
    source_app: string | null;
    /** Deep link back to the originating record in that app. */
    source_url: string | null;
    /** RRULE string when the event repeats, else null. */
    rrule: string | null;
    /** Minutes before start to remind, or null for no reminder. */
    reminder_minutes: number | null;
    /** The series anchor times (for editing the whole series), null when single. */
    series_starts_at: string | null;
    series_ends_at: string | null;
}

export interface WritableCalendar {
    id: number;
    name: string;
    color: string;
    is_default: boolean;
}

export interface EventTemplate {
    id: number;
    name: string;
    /** Calendar events land on; null falls back to the default calendar. */
    calendar_id: number | null;
    title: string;
    description: string | null;
    location: string | null;
    all_day: boolean;
    /** Timed: length in minutes. All-day: days x 1440. */
    duration_minutes: number;
    /** "HH:MM" to default the start time to, or null. */
    default_start_time: string | null;
    /** Repeat pattern (daily/weekly/monthly/yearly), or null when it does not repeat. */
    frequency: string | null;
    /** Minutes before start to remind, or null for no reminder. */
    reminder_minutes: number | null;
}
