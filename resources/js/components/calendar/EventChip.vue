<script setup lang="ts">
import { Mail, Repeat } from '@lucide/vue';
import { computed } from 'vue';
import { formatEventTime } from '@/composables/useCalendarGrid';
import { sourceLink } from '@/lib/eventSource';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{ event: CalendarEvent }>();

const time = computed(() => formatEventTime(props.event));
const fromMail = computed(() => props.event.source_app === 'zero');
const source = computed(() => sourceLink(props.event));

const style = computed(() => {
    const color = props.event.color;

    if (props.event.all_day) {
        return { backgroundColor: color, color: '#ffffff' };
    }

    return {
        backgroundColor: `color-mix(in srgb, ${color} var(--chip-tint), transparent)`,
        color: `color-mix(in srgb, ${color} 82%, var(--chip-text-mix))`,
    };
});
</script>

<template>
    <div
        class="flex items-center gap-1 truncate rounded-md px-1.5 py-0.5 text-xs font-medium transition-[filter] hover:brightness-105"
        :style="style"
        :title="event.title"
    >
        <span
            v-if="time"
            class="shrink-0 font-semibold tabular-nums opacity-80"
        >
            {{ time }}
        </span>
        <Mail v-if="fromMail && source" class="size-3 shrink-0 opacity-70" />
        <Repeat v-if="event.rrule" class="size-3 shrink-0 opacity-70" />
        <span class="truncate">{{ event.title }}</span>
    </div>
</template>
