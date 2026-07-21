<script setup lang="ts">
import { CalendarX } from '@lucide/vue';
import { computed } from 'vue';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{
    events: CalendarEvent[];
    today: string;
}>();

const emit = defineEmits<{ 'select-event': [CalendarEvent] }>();

const pad = (n: number) => String(n).padStart(2, '0');

// All-day events are stored at midnight UTC, so key them off the UTC date;
// timed events group by the viewer's local day.
function dayKey(event: CalendarEvent): string {
    if (event.all_day) {
        return event.starts_at.slice(0, 10);
    }

    const d = new Date(event.starts_at);

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function timeLabel(event: CalendarEvent): string {
    if (event.all_day) {
        return 'All day';
    }

    const d = new Date(event.starts_at);

    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function dayHeader(key: string): string {
    const [y, m, d] = key.split('-').map(Number);

    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(y, m - 1, d));
}

const groups = computed(() => {
    const map = new Map<string, CalendarEvent[]>();

    for (const event of props.events) {
        const key = dayKey(event);
        (map.get(key) ?? map.set(key, []).get(key)!).push(event);
    }

    return [...map.entries()]
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([key, events]) => ({ key, events }));
});
</script>

<template>
    <div class="flex-1 overflow-y-auto">
        <div v-if="groups.length" class="mx-auto max-w-2xl space-y-6">
            <section v-for="group in groups" :key="group.key" class="space-y-1">
                <h2
                    class="sticky top-0 bg-background py-1 text-sm font-semibold"
                >
                    <span v-if="group.key === today" class="text-primary">
                        Today
                        <span class="font-normal text-muted-foreground">
                            · {{ dayHeader(group.key) }}
                        </span>
                    </span>
                    <span v-else>{{ dayHeader(group.key) }}</span>
                </h2>

                <button
                    v-for="event in group.events"
                    :key="event.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-accent"
                    @click="emit('select-event', event)"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: event.color }"
                    />
                    <span
                        class="w-16 shrink-0 text-sm tabular-nums text-muted-foreground"
                    >
                        {{ timeLabel(event) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ event.title }}
                        </span>
                        <span
                            v-if="event.location"
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{ event.location }}
                        </span>
                    </span>
                </button>
            </section>
        </div>

        <div
            v-else
            class="flex h-full flex-col items-center justify-center gap-2 py-16 text-muted-foreground"
        >
            <CalendarX class="size-8" />
            <p class="text-sm">No events to show.</p>
        </div>
    </div>
</template>
