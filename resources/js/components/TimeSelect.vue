<script setup lang="ts">
import { computed } from 'vue';

// A 24-hour time picker that doesn't depend on the browser's locale (native
// <input type="time"> renders 12-hour AM/PM under a 12-hour locale, which is
// ambiguous). Binds a "HH:MM" string.
const props = defineProps<{ modelValue: string; id?: string }>();
const emit = defineEmits<{ 'update:modelValue': [string] }>();

const pad = (n: number) => String(n).padStart(2, '0');
const hours = Array.from({ length: 24 }, (_, i) => pad(i));
const minutes = Array.from({ length: 60 }, (_, i) => pad(i));

const hour = computed(() => (props.modelValue || '00:00').slice(0, 2));
const minute = computed(() => (props.modelValue || '00:00').slice(3, 5));

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

function setHour(value: string): void {
    emit('update:modelValue', `${value}:${minute.value}`);
}

function setMinute(value: string): void {
    emit('update:modelValue', `${hour.value}:${value}`);
}
</script>

<template>
    <div class="flex items-center gap-1">
        <select
            :id="id"
            :value="hour"
            :class="selectClass"
            aria-label="Hour"
            @change="setHour(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
        </select>
        <span class="text-sm text-muted-foreground">:</span>
        <select
            :value="minute"
            :class="selectClass"
            aria-label="Minute"
            @change="setMinute(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
        </select>
    </div>
</template>
