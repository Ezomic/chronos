import type { CalendarEvent } from '@/types/calendar';

// Only apps we recognise get their source_url rendered as a link, so an
// arbitrary stored URL can never be turned into a clickable anchor.
const KNOWN_SOURCES: Record<string, string> = {
    zero: 'Open in Mail',
    tracker: 'Open in Tracker',
};

export interface SourceLink {
    href: string;
    label: string;
}

export function sourceLink(event: CalendarEvent): SourceLink | null {
    if (!event.source_app || !event.source_url) {
        return null;
    }

    const label = KNOWN_SOURCES[event.source_app];

    if (!label) {
        return null;
    }

    return { href: event.source_url, label };
}

export function hasKnownSource(event: CalendarEvent): boolean {
    return sourceLink(event) !== null;
}
