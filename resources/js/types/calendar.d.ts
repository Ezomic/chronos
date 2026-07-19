export interface CalendarEvent {
    /** Unique per occurrence (a recurring series yields many with the same id). */
    key: string;
    /** The database event id (the master, for recurring series). */
    id: number;
    calendar_id: number;
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
