import type { CalendarEvent } from '@/types/calendar';

export interface SourceLink {
    href: string;
    label: string;
}

/**
 * Only apps Chronos knows about get their source_url rendered as a link, so an
 * arbitrary stored URL can never be turned into a clickable anchor. The labels
 * come from config (chronos.consumers) via shared Inertia props, so onboarding
 * a consumer does not need a frontend change.
 */
export function sourceLink(
    event: CalendarEvent,
    labels: Record<string, string>,
): SourceLink | null {
    if (!event.source_app || !event.source_url) {
        return null;
    }

    const label = labels[event.source_app];

    if (!label) {
        return null;
    }

    return { href: event.source_url, label };
}
