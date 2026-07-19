<script setup lang="ts">
import {
    parseAbsolute,
    parseDate,
    startOfWeek,
    toCalendarDate,
} from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{
    anchor: string;
    view: string;
    events: CalendarEvent[];
    today: string;
}>();

const emit = defineEmits<{
    'select-day': [string];
    'select-event': [CalendarEvent];
}>();

const LOCALE = 'nl-NL';
const HOUR_PX = 48;
const MIN_BLOCK_PX = 22;

interface Block {
    event: CalendarEvent;
    top: number;
    height: number;
    lane: number;
    lanes: number;
}

interface Column {
    date: CalendarDate;
    key: string;
    weekday: string;
    day: number;
    isToday: boolean;
    allDay: CalendarEvent[];
    blocks: Block[];
}

const hours = Array.from({ length: 24 }, (_, i) => i);

const days = computed<CalendarDate[]>(() => {
    const anchor = parseDate(props.anchor);

    if (props.view === 'day') {
        return [anchor];
    }

    const start = startOfWeek(anchor, LOCALE);

    return Array.from({ length: 7 }, (_, i) => start.add({ days: i }));
});

function minutesInDay(iso: string, timezone: string): number {
    const z = parseAbsolute(iso, timezone);

    return z.hour * 60 + z.minute;
}

function layout(events: CalendarEvent[]): Block[] {
    const sorted = [...events].sort((a, b) =>
        a.starts_at < b.starts_at ? -1 : 1,
    );
    const laneEnds: number[] = [];

    const placed = sorted.map((event) => {
        const startKey = toCalendarDate(
            parseAbsolute(event.starts_at, event.timezone),
        ).toString();
        const endKey = toCalendarDate(
            parseAbsolute(event.ends_at, event.timezone),
        ).toString();

        const startMin = minutesInDay(event.starts_at, event.timezone);
        const endMin =
            endKey === startKey
                ? minutesInDay(event.ends_at, event.timezone)
                : 1440;

        let lane = laneEnds.findIndex((end) => end <= startMin);

        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(endMin);
        } else {
            laneEnds[lane] = endMin;
        }

        return {
            event,
            top: (startMin / 60) * HOUR_PX,
            height: Math.max(
                ((endMin - startMin) / 60) * HOUR_PX,
                MIN_BLOCK_PX,
            ),
            lane,
        };
    });

    const lanes = Math.max(laneEnds.length, 1);

    return placed.map((block) => ({ ...block, lanes }));
}

const columns = computed<Column[]>(() =>
    days.value.map((date) => {
        const key = date.toString();

        const timed: CalendarEvent[] = [];
        const allDay: CalendarEvent[] = [];

        for (const event of props.events) {
            if (event.all_day) {
                const start = toCalendarDate(
                    parseAbsolute(event.starts_at, event.timezone),
                );
                const end = toCalendarDate(
                    parseAbsolute(event.ends_at, event.timezone),
                ).subtract({ days: 1 });

                if (date.compare(start) >= 0 && date.compare(end) <= 0) {
                    allDay.push(event);
                }

                continue;
            }

            const startKey = toCalendarDate(
                parseAbsolute(event.starts_at, event.timezone),
            ).toString();

            if (startKey === key) {
                timed.push(event);
            }
        }

        return {
            date,
            key,
            weekday: new Intl.DateTimeFormat('en-US', {
                weekday: 'short',
            }).format(new Date(date.year, date.month - 1, date.day)),
            day: date.day,
            isToday: key === props.today,
            allDay,
            blocks: layout(timed),
        };
    }),
);

const hasAllDay = computed(() =>
    columns.value.some((c) => c.allDay.length > 0),
);
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden rounded-xl border">
        <div class="flex border-b">
            <div class="w-14 shrink-0" />
            <div
                class="grid flex-1"
                :style="{
                    gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))`,
                }"
            >
                <button
                    v-for="column in columns"
                    :key="column.key"
                    type="button"
                    class="flex flex-col items-center gap-0.5 border-l py-2 first:border-l-0 hover:bg-accent/40"
                    @click="emit('select-day', column.key)"
                >
                    <span class="text-xs text-muted-foreground">
                        {{ column.weekday }}
                    </span>
                    <span
                        :class="
                            cn(
                                'flex size-7 items-center justify-center rounded-full text-sm',
                                column.isToday &&
                                    'bg-primary font-semibold text-primary-foreground',
                            )
                        "
                    >
                        {{ column.day }}
                    </span>
                </button>
            </div>
        </div>

        <div v-if="hasAllDay" class="flex border-b bg-muted/20">
            <div
                class="flex w-14 shrink-0 items-center justify-end pr-2 text-xs text-muted-foreground"
            >
                all-day
            </div>
            <div
                class="grid flex-1"
                :style="{
                    gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))`,
                }"
            >
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="flex flex-col gap-0.5 border-l p-1 first:border-l-0"
                >
                    <button
                        v-for="event in column.allDay"
                        :key="event.key"
                        type="button"
                        class="flex items-center gap-1 truncate rounded px-1 py-0.5 text-left text-xs hover:opacity-80"
                        :style="{
                            backgroundColor: event.color + '33',
                            borderLeft: `3px solid ${event.color}`,
                        }"
                        @click.stop="emit('select-event', event)"
                    >
                        <span class="truncate">{{ event.title }}</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="flex flex-1 overflow-y-auto">
            <div class="w-14 shrink-0">
                <div
                    v-for="hour in hours"
                    :key="hour"
                    class="relative border-b text-right"
                    :style="{ height: `${HOUR_PX}px` }"
                >
                    <span
                        class="absolute -top-2 right-2 text-xs text-muted-foreground"
                    >
                        {{ hour === 0 ? '' : `${hour}:00` }}
                    </span>
                </div>
            </div>

            <div
                class="grid flex-1"
                :style="{
                    gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))`,
                }"
            >
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="relative border-l first:border-l-0"
                    :style="{ height: `${hours.length * HOUR_PX}px` }"
                    @click="emit('select-day', column.key)"
                >
                    <div
                        v-for="hour in hours"
                        :key="hour"
                        class="border-b"
                        :style="{ height: `${HOUR_PX}px` }"
                    />

                    <button
                        v-for="block in column.blocks"
                        :key="block.event.key"
                        type="button"
                        class="absolute overflow-hidden rounded px-1 py-0.5 text-left text-xs hover:opacity-90"
                        :style="{
                            top: `${block.top}px`,
                            height: `${block.height}px`,
                            left: `calc(${(block.lane / block.lanes) * 100}% + 2px)`,
                            width: `calc(${100 / block.lanes}% - 4px)`,
                            backgroundColor: block.event.color + '33',
                            borderLeft: `3px solid ${block.event.color}`,
                        }"
                        @click.stop="emit('select-event', block.event)"
                    >
                        <span class="block truncate font-medium">
                            {{ block.event.title }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
