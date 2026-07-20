<script setup lang="ts">
import {
    parseAbsolute,
    parseDate,
    startOfWeek,
    toCalendarDate,
} from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { computed, onBeforeUnmount, ref } from 'vue';
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
    'create-range': [{ start: string; end: string }];
    reschedule: [{ event: CalendarEvent; start: string; end: string }];
}>();

const LOCALE = 'nl-NL';
const HOUR_PX = 48;
const MIN_BLOCK_PX = 22;
const SNAP_MIN = 15;
const DAY_MIN = 1440;
const CLICK_PX = 4;

interface Block {
    event: CalendarEvent;
    top: number;
    height: number;
    lane: number;
    lanes: number;
    startMin: number;
    endMin: number;
    movable: boolean;
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
            startMin,
            endMin,
            // Only plain timed events can be dragged; all-day and recurring
            // occurrences have ambiguous or off-grid semantics.
            movable: !event.all_day && !event.rrule,
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

// --- drag interactions -----------------------------------------------------

const columnsEl = ref<HTMLElement | null>(null);

interface DragState {
    mode: 'create' | 'move' | 'resize' | 'select';
    event: CalendarEvent | null;
    colIndex: number;
    anchorMin: number;
    startMin: number;
    endMin: number;
    grabOffsetMin: number;
    duration: number;
    moved: boolean;
    startX: number;
    startY: number;
}

const drag = ref<DragState | null>(null);

const clamp = (n: number, lo: number, hi: number) =>
    Math.min(Math.max(n, lo), hi);

const pad = (n: number) => String(n).padStart(2, '0');

function yToMinutes(clientY: number, rect: DOMRect): number {
    const raw = ((clientY - rect.top) / HOUR_PX) * 60;

    return clamp(Math.round(raw / SNAP_MIN) * SNAP_MIN, 0, DAY_MIN);
}

function xToColumn(clientX: number, rect: DOMRect): number {
    const width = rect.width / columns.value.length;

    return clamp(
        Math.floor((clientX - rect.left) / width),
        0,
        columns.value.length - 1,
    );
}

function localDateTime(date: CalendarDate, minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return `${date.year}-${pad(date.month)}-${pad(date.day)}T${pad(h)}:${pad(m)}`;
}

function startCreate(colIndex: number, e: PointerEvent): void {
    if (e.button !== 0 || !columnsEl.value) {
        return;
    }

    const rect = columnsEl.value.getBoundingClientRect();
    const min = yToMinutes(e.clientY, rect);

    drag.value = {
        mode: 'create',
        event: null,
        colIndex,
        anchorMin: min,
        startMin: min,
        endMin: Math.min(min + SNAP_MIN, DAY_MIN),
        grabOffsetMin: 0,
        duration: 0,
        moved: false,
        startX: e.clientX,
        startY: e.clientY,
    };

    attach();
}

function startBlock(colIndex: number, block: Block, e: PointerEvent): void {
    if (e.button !== 0 || !columnsEl.value) {
        return;
    }

    e.stopPropagation();

    if (!block.movable) {
        // Still capture the gesture so a plain click opens the event.
        drag.value = {
            mode: 'select',
            event: block.event,
            colIndex,
            anchorMin: block.startMin,
            startMin: block.startMin,
            endMin: block.endMin,
            grabOffsetMin: 0,
            duration: block.endMin - block.startMin,
            moved: false,
            startX: e.clientX,
            startY: e.clientY,
        };
        attach();

        return;
    }

    const rect = columnsEl.value.getBoundingClientRect();
    const pointerMin = yToMinutes(e.clientY, rect);

    drag.value = {
        mode: 'move',
        event: block.event,
        colIndex,
        anchorMin: block.startMin,
        startMin: block.startMin,
        endMin: block.endMin,
        grabOffsetMin: pointerMin - block.startMin,
        duration: block.endMin - block.startMin,
        moved: false,
        startX: e.clientX,
        startY: e.clientY,
    };

    attach();
}

function startResize(colIndex: number, block: Block, e: PointerEvent): void {
    if (e.button !== 0 || !block.movable) {
        return;
    }

    e.stopPropagation();

    drag.value = {
        mode: 'resize',
        event: block.event,
        colIndex,
        anchorMin: block.startMin,
        startMin: block.startMin,
        endMin: block.endMin,
        grabOffsetMin: 0,
        duration: block.endMin - block.startMin,
        moved: false,
        startX: e.clientX,
        startY: e.clientY,
    };

    attach();
}

function onPointerMove(e: PointerEvent): void {
    const d = drag.value;

    if (!d || !columnsEl.value) {
        return;
    }

    if (Math.hypot(e.clientX - d.startX, e.clientY - d.startY) > CLICK_PX) {
        d.moved = true;
    }

    const rect = columnsEl.value.getBoundingClientRect();
    const min = yToMinutes(e.clientY, rect);

    if (d.mode === 'create') {
        const lo = Math.min(min, d.anchorMin);
        const hi = Math.max(min, d.anchorMin);
        d.startMin = lo;
        d.endMin = Math.max(hi, lo + SNAP_MIN);
    } else if (d.mode === 'move') {
        d.colIndex = xToColumn(e.clientX, rect);
        const start = clamp(min - d.grabOffsetMin, 0, DAY_MIN - d.duration);
        d.startMin = Math.round(start / SNAP_MIN) * SNAP_MIN;
        d.endMin = d.startMin + d.duration;
    } else if (d.mode === 'resize') {
        d.endMin = clamp(Math.max(min, d.startMin + SNAP_MIN), 0, DAY_MIN);
    }
}

function onPointerUp(): void {
    const d = drag.value;
    detach();
    drag.value = null;

    if (!d) {
        return;
    }

    if (d.mode === 'create') {
        if (!d.moved) {
            emit('select-day', columns.value[d.colIndex].key);

            return;
        }

        const date = columns.value[d.colIndex].date;
        emit('create-range', {
            start: localDateTime(date, d.startMin),
            end: localDateTime(date, d.endMin),
        });

        return;
    }

    // move / resize / select
    if (!d.moved || !d.event) {
        if (d.event) {
            emit('select-event', d.event);
        }

        return;
    }

    if (d.mode === 'select') {
        return;
    }

    const date = columns.value[d.colIndex].date;
    emit('reschedule', {
        event: d.event,
        start: localDateTime(date, d.startMin),
        end: localDateTime(date, d.endMin),
    });
}

function attach(): void {
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
}

function detach(): void {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
}

onBeforeUnmount(detach);

const preview = computed(() => {
    const d = drag.value;

    if (!d || !d.moved || d.mode === 'select') {
        return null;
    }

    const width = 100 / columns.value.length;

    return {
        left: `${d.colIndex * width}%`,
        width: `${width}%`,
        top: `${(d.startMin / 60) * HOUR_PX}px`,
        height: `${Math.max(((d.endMin - d.startMin) / 60) * HOUR_PX, MIN_BLOCK_PX)}px`,
        label: `${pad(Math.floor(d.startMin / 60))}:${pad(d.startMin % 60)} – ${pad(Math.floor(d.endMin / 60))}:${pad(d.endMin % 60)}`,
        create: d.mode === 'create',
        color: d.event?.color,
    };
});
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
                                'flex size-7 items-center justify-center rounded-full text-sm tabular-nums',
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
                ref="columnsEl"
                :class="cn('relative grid flex-1', drag && 'select-none')"
                :style="{
                    gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))`,
                }"
            >
                <div
                    v-for="(column, ci) in columns"
                    :key="column.key"
                    class="relative touch-none border-l first:border-l-0"
                    :style="{ height: `${hours.length * HOUR_PX}px` }"
                    @pointerdown="startCreate(ci, $event)"
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
                        :class="
                            cn(
                                'absolute overflow-hidden rounded px-1 py-0.5 text-left text-xs hover:opacity-90',
                                block.movable
                                    ? 'cursor-grab active:cursor-grabbing'
                                    : 'cursor-pointer',
                            )
                        "
                        :style="{
                            top: `${block.top}px`,
                            height: `${block.height}px`,
                            left: `calc(${(block.lane / block.lanes) * 100}% + 2px)`,
                            width: `calc(${100 / block.lanes}% - 4px)`,
                            backgroundColor: block.event.color + '33',
                            borderLeft: `3px solid ${block.event.color}`,
                        }"
                        @pointerdown="startBlock(ci, block, $event)"
                    >
                        <span class="block truncate font-medium">
                            {{ block.event.title }}
                        </span>
                        <span
                            v-if="block.movable"
                            class="absolute inset-x-0 bottom-0 h-1.5 cursor-ns-resize"
                            @pointerdown="startResize(ci, block, $event)"
                        />
                    </button>
                </div>

                <div
                    v-if="preview"
                    class="pointer-events-none absolute z-10 overflow-hidden rounded px-1 py-0.5 text-xs font-medium"
                    :class="
                        preview.create
                            ? 'border border-dashed border-primary bg-primary/15 text-primary'
                            : 'text-white shadow-lg'
                    "
                    :style="{
                        left: preview.left,
                        width: preview.width,
                        top: preview.top,
                        height: preview.height,
                        backgroundColor: preview.create
                            ? undefined
                            : preview.color,
                    }"
                >
                    {{ preview.label }}
                </div>
            </div>
        </div>
    </div>
</template>
