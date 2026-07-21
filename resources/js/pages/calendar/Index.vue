<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    getLocalTimeZone,
    parseDate,
    startOfWeek,
    today,
} from '@internationalized/date';
import { ChevronLeft, ChevronRight, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AgendaList from '@/components/calendar/AgendaList.vue';
import EventSheet from '@/components/calendar/EventSheet.vue';
import MonthGrid from '@/components/calendar/MonthGrid.vue';
import WeekGrid from '@/components/calendar/WeekGrid.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { index as calendarIndex } from '@/routes/calendar';
import type {
    CalendarEvent,
    EventTemplate,
    WritableCalendar,
} from '@/types/calendar';

const props = defineProps<{
    view: string;
    date: string;
    query: string;
    events: CalendarEvent[];
    calendars: WritableCalendar[];
    templates: EventTemplate[];
}>();

const sheetOpen = ref(false);
const activeEvent = ref<CalendarEvent | null>(null);
const activeDate = ref<string | null>(null);

function openCreate(date: string | null = null): void {
    activeEvent.value = null;
    activeDate.value = date;
    sheetOpen.value = true;
}

function openEvent(event: CalendarEvent): void {
    activeEvent.value = event;
    activeDate.value = null;
    sheetOpen.value = true;
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendar', href: calendarIndex() }],
    },
});

const anchor = computed(() => parseDate(props.date));
const todayKey = computed(() => today(getLocalTimeZone()).toString());

const search = ref(props.query);

function runSearch(): void {
    router.get(
        calendarIndex({
            query: {
                view: 'agenda',
                date: props.date,
                q: search.value || undefined,
            },
        }).url,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Debounce live searching so each keystroke doesn't fire a visit.
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 300);
});

function clearSearch(): void {
    search.value = '';
}

const asDate = (d: { year: number; month: number; day: number }) =>
    new Date(d.year, d.month - 1, d.day);

const heading = computed(() => {
    const a = anchor.value;

    if (props.view === 'agenda') {
        return props.query ? `Search: “${props.query}”` : 'Agenda';
    }

    if (props.view === 'day') {
        return new Intl.DateTimeFormat('en-US', {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        }).format(asDate(a));
    }

    if (props.view === 'week') {
        const monday = startOfWeek(a, 'nl-NL');
        const sunday = monday.add({ days: 6 });
        const fmt = new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
        });

        return `${fmt.format(asDate(monday))} – ${fmt.format(asDate(sunday))}, ${sunday.year}`;
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'long',
        year: 'numeric',
    }).format(asDate(a));
});

const step = (direction: 1 | -1) => {
    const a = anchor.value;
    const moved =
        props.view === 'day'
            ? a.add({ days: direction })
            : props.view === 'week'
              ? a.add({ weeks: direction })
              : props.view === 'agenda'
                ? a.add({ days: direction * 30 })
                : a.set({ day: 1 }).add({ months: direction });

    return moved.toString();
};

const prev = computed(() => step(-1));
const next = computed(() => step(1));

const hrefFor = (date: string) =>
    calendarIndex({
        query: {
            view: props.view,
            date,
            q: props.view === 'agenda' && props.query ? props.query : undefined,
        },
    });

const viewHref = (view: string) =>
    calendarIndex({ query: { view, date: props.date } });

const views = [
    { key: 'month', label: 'Month' },
    { key: 'week', label: 'Week' },
    { key: 'day', label: 'Day' },
    { key: 'agenda', label: 'Agenda' },
];
</script>

<template>
    <Head title="Calendar" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ heading }}</h1>

            <div class="flex items-center gap-2">
                <div class="flex items-center rounded-md border p-0.5">
                    <Button
                        v-for="option in views"
                        :key="option.key"
                        variant="ghost"
                        size="sm"
                        as-child
                        :class="
                            cn(
                                'h-7',
                                props.view === option.key &&
                                    'bg-accent text-accent-foreground',
                            )
                        "
                    >
                        <Link :href="viewHref(option.key)">{{
                            option.label
                        }}</Link>
                    </Button>
                </div>

                <Button variant="outline" size="sm" as-child>
                    <Link :href="hrefFor(todayKey)">Today</Link>
                </Button>
                <div class="flex items-center">
                    <Button variant="ghost" size="icon" as-child>
                        <Link :href="hrefFor(prev)" aria-label="Previous">
                            <ChevronLeft class="size-4" />
                        </Link>
                    </Button>
                    <Button variant="ghost" size="icon" as-child>
                        <Link :href="hrefFor(next)" aria-label="Next">
                            <ChevronRight class="size-4" />
                        </Link>
                    </Button>
                </div>
                <Button size="sm" @click="openCreate()">
                    <Plus class="size-4" />
                    New event
                </Button>
            </div>
        </div>

        <div
            v-if="props.view === 'agenda'"
            class="relative mx-auto w-full max-w-2xl"
        >
            <Search
                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                placeholder="Search events…"
                class="px-9"
                @keyup.enter="runSearch"
            />
            <button
                v-if="search"
                type="button"
                class="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                aria-label="Clear search"
                @click="clearSearch"
            >
                <X class="size-4" />
            </button>
        </div>

        <MonthGrid
            v-if="props.view === 'month'"
            :anchor="props.date"
            :events="props.events"
            :today="todayKey"
            @select-day="openCreate"
            @select-event="openEvent"
        />
        <AgendaList
            v-else-if="props.view === 'agenda'"
            :events="props.events"
            :today="todayKey"
            @select-event="openEvent"
        />
        <WeekGrid
            v-else
            :anchor="props.date"
            :view="props.view"
            :events="props.events"
            :today="todayKey"
            @select-day="openCreate"
            @select-event="openEvent"
        />

        <EventSheet
            v-model:open="sheetOpen"
            :event="activeEvent"
            :default-date="activeDate"
            :calendars="props.calendars"
            :templates="props.templates"
        />
    </div>
</template>
