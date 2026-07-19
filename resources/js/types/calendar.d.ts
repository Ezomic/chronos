export interface CalendarEvent {
    id: number;
    calendar_id: number;
    title: string;
    description: string | null;
    color: string;
    all_day: boolean;
    /** ISO 8601 UTC instant. */
    starts_at: string;
    /** ISO 8601 UTC instant, exclusive. */
    ends_at: string;
    /** IANA zone the event was authored in. */
    timezone: string;
    location: string | null;
}

export interface WritableCalendar {
    id: number;
    name: string;
    color: string;
    is_default: boolean;
}
