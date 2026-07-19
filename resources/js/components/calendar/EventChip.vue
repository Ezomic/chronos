<script setup lang="ts">
import { Mail } from '@lucide/vue';
import { computed } from 'vue';
import { formatEventTime } from '@/composables/useCalendarGrid';
import { sourceLink } from '@/lib/eventSource';
import type { CalendarEvent } from '@/types/calendar';

const props = defineProps<{ event: CalendarEvent }>();

const time = computed(() => formatEventTime(props.event));
const fromMail = computed(() => props.event.source_app === 'zero');
const source = computed(() => sourceLink(props.event));
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
        <Mail
            v-if="fromMail && source"
            class="size-3 shrink-0 text-muted-foreground"
        />
        <span class="truncate">{{ event.title }}</span>
    </div>
</template>
