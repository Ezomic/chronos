<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import MonthGrid from '@/components/calendar/MonthGrid.vue';
import { Button } from '@/components/ui/button';
import { index as calendarIndex } from '@/routes/calendar';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{
    view: string;
    date: string;
    events: CalendarEvent[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendar', href: calendarIndex() }],
    },
});

const anchor = computed(() => parseDate(props.date));
const todayKey = computed(() => today(getLocalTimeZone()).toString());

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('en-US', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(anchor.value.year, anchor.value.month - 1, 1)),
);

const prevMonth = computed(() =>
    anchor.value.set({ day: 1 }).subtract({ months: 1 }).toString(),
);
const nextMonth = computed(() =>
    anchor.value.set({ day: 1 }).add({ months: 1 }).toString(),
);

const hrefFor = (date: string) => calendarIndex({ query: { date } });
</script>

<template>
    <Head title="Calendar" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">{{ monthLabel }}</h1>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="hrefFor(todayKey)">Today</Link>
                </Button>
                <div class="flex items-center">
                    <Button variant="ghost" size="icon" as-child>
                        <Link
                            :href="hrefFor(prevMonth)"
                            aria-label="Previous month"
                        >
                            <ChevronLeft class="size-4" />
                        </Link>
                    </Button>
                    <Button variant="ghost" size="icon" as-child>
                        <Link
                            :href="hrefFor(nextMonth)"
                            aria-label="Next month"
                        >
                            <ChevronRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <MonthGrid
            :anchor="props.date"
            :events="props.events"
            :today="todayKey"
        />
    </div>
</template>
