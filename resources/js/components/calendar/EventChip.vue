<script setup lang="ts">
import { computed } from 'vue';
import { formatEventTime } from '@/composables/useCalendarGrid';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{ event: CalendarEvent }>();

const time = computed(() => formatEventTime(props.event));
</script>

<template>
    <div
        class="flex items-center gap-1 truncate rounded px-1 py-0.5 text-xs hover:bg-accent"
        :title="event.title"
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :style="{ backgroundColor: event.color }"
        />
        <span v-if="time" class="shrink-0 text-muted-foreground tabular-nums">
            {{ time }}
        </span>
        <span class="truncate">{{ event.title }}</span>
    </div>
</template>
