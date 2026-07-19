<script setup lang="ts">
import EventChip from '@/components/calendar/EventChip.vue';
import { useCalendarGrid } from '@/composables/useCalendarGrid';
import { cn } from '@/lib/utils';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{
    anchor: string;
    events: CalendarEvent[];
    today: string;
}>();

const emit = defineEmits<{
    'select-day': [string];
    'select-event': [CalendarEvent];
}>();

const { weeks } = useCalendarGrid(
    () => props.anchor,
    () => props.events,
    () => props.today,
);

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const MAX_VISIBLE = 3;
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden rounded-xl border">
        <div
            class="grid grid-cols-7 border-b bg-muted/40 text-xs font-medium text-muted-foreground"
        >
            <div
                v-for="day in weekdays"
                :key="day"
                class="px-2 py-2 text-center"
            >
                {{ day }}
            </div>
        </div>

        <div class="grid flex-1 grid-rows-6">
            <div v-for="(week, wi) in weeks" :key="wi" class="grid grid-cols-7">
                <div
                    v-for="cell in week"
                    :key="cell.key"
                    :class="
                        cn(
                            'flex min-h-24 cursor-pointer flex-col gap-0.5 border-t border-l p-1 first:border-l-0 hover:bg-accent/40',
                            !cell.inCurrentMonth &&
                                'bg-muted/30 text-muted-foreground',
                        )
                    "
                    @click="emit('select-day', cell.key)"
                >
                    <div class="flex justify-end">
                        <span
                            :class="
                                cn(
                                    'flex size-6 items-center justify-center rounded-full text-xs',
                                    cell.isToday &&
                                        'bg-primary font-semibold text-primary-foreground',
                                )
                            "
                        >
                            {{ cell.day }}
                        </span>
                    </div>

                    <div class="flex flex-col gap-0.5 overflow-hidden">
                        <EventChip
                            v-for="event in cell.events.slice(0, MAX_VISIBLE)"
                            :key="event.key"
                            :event="event"
                            class="cursor-pointer"
                            @click.stop="emit('select-event', event)"
                        />
                        <span
                            v-if="cell.events.length > MAX_VISIBLE"
                            class="px-1 text-xs text-muted-foreground"
                        >
                            +{{ cell.events.length - MAX_VISIBLE }} more
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
