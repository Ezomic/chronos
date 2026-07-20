<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    getLocalTimeZone,
    parseAbsolute,
    toCalendarDate,
} from '@internationalized/date';
import { ExternalLink } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { sourceLink } from '@/lib/eventSource';
import { destroy, store, update } from '@/routes/events';
import type { CalendarEvent, WritableCalendar } from '@/types/calendar';

const props = defineProps<{
    open: boolean;
    event: CalendarEvent | null;
    defaultDate: string | null;
    defaultStart?: string | null;
    defaultEnd?: string | null;
    calendars: WritableCalendar[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

interface FormState {
    calendar_id: number | null;
    title: string;
    description: string;
    location: string;
    all_day: boolean;
    start: string;
    end: string;
    frequency: string;
    until: string;
    reminder: string;
}

const form = reactive<FormState>({
    calendar_id: null,
    title: '',
    description: '',
    location: '',
    all_day: false,
    start: '',
    end: '',
    frequency: 'none',
    until: '',
    reminder: 'none',
});

const reminderOptions = [
    { value: 'none', label: 'No reminder' },
    { value: '0', label: 'At start time' },
    { value: '5', label: '5 minutes before' },
    { value: '10', label: '10 minutes before' },
    { value: '15', label: '15 minutes before' },
    { value: '30', label: '30 minutes before' },
    { value: '60', label: '1 hour before' },
    { value: '120', label: '2 hours before' },
    { value: '1440', label: '1 day before' },
];

const repeatOptions = [
    { value: 'none', label: 'Does not repeat' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
];

const FREQ_MAP: Record<string, string> = {
    DAILY: 'daily',
    WEEKLY: 'weekly',
    MONTHLY: 'monthly',
    YEARLY: 'yearly',
};

function parseRrule(rrule: string | null): {
    frequency: string;
    until: string;
} {
    if (!rrule) {
        return { frequency: 'none', until: '' };
    }

    const freq = rrule.match(/FREQ=(\w+)/);
    const until = rrule.match(/UNTIL=(\d{4})(\d{2})(\d{2})/);

    return {
        frequency: (freq && FREQ_MAP[freq[1]]) || 'none',
        until: until ? `${until[1]}-${until[2]}-${until[3]}` : '',
    };
}

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const source = computed(() => (props.event ? sourceLink(props.event) : null));

const pad = (n: number) => String(n).padStart(2, '0');

function localDateTime(iso: string, timezone: string): string {
    const z = parseAbsolute(iso, timezone);

    return `${z.year}-${pad(z.month)}-${pad(z.day)}T${pad(z.hour)}:${pad(z.minute)}`;
}

function hydrate(): void {
    errors.value = {};
    const fallback =
        props.calendars.find((c) => c.is_default) ?? props.calendars[0];

    if (props.event) {
        const e = props.event;
        form.calendar_id = e.calendar_id;
        form.title = e.title;
        form.description = e.description ?? '';
        form.location = e.location ?? '';
        form.all_day = e.all_day;

        const recurrence = parseRrule(e.rrule);
        form.frequency = recurrence.frequency;
        form.until = recurrence.until;
        form.reminder =
            e.reminder_minutes === null || e.reminder_minutes === undefined
                ? 'none'
                : String(e.reminder_minutes);

        // Editing a series edits its anchor, not the clicked occurrence.
        const start = e.series_starts_at ?? e.starts_at;
        const end = e.series_ends_at ?? e.ends_at;

        if (e.all_day) {
            form.start = toCalendarDate(parseAbsolute(start, 'UTC')).toString();
            form.end = toCalendarDate(parseAbsolute(end, 'UTC'))
                .subtract({ days: 1 })
                .toString();
        } else {
            form.start = localDateTime(start, e.timezone);
            form.end = localDateTime(end, e.timezone);
        }

        return;
    }

    const date = props.defaultDate ?? new Date().toISOString().slice(0, 10);
    form.calendar_id = fallback?.id ?? null;
    form.title = '';
    form.description = '';
    form.location = '';
    // A drag on the grid hands us an explicit timed range; otherwise fall back
    // to a 09:00 default on the chosen day.
    form.all_day = false;
    form.start = props.defaultStart ?? `${date}T09:00`;
    form.end = props.defaultEnd ?? `${date}T10:00`;
    form.frequency = 'none';
    form.until = '';
    form.reminder = 'none';
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            hydrate();
        }
    },
);

// Keep the date/time inputs valid as the all-day toggle flips their type.
watch(
    () => form.all_day,
    (allDay) => {
        if (allDay) {
            form.start = form.start.slice(0, 10);
            form.end = form.end.slice(0, 10);
            // Reminders are only offered for timed events.
            form.reminder = 'none';
        } else {
            if (form.start.length === 10) {
                form.start += 'T09:00';
            }

            if (form.end.length === 10) {
                form.end += 'T10:00';
            }
        }
    },
);

function payload() {
    return {
        calendar_id: form.calendar_id,
        title: form.title,
        description: form.description || null,
        location: form.location || null,
        all_day: form.all_day,
        timezone: form.all_day ? null : getLocalTimeZone(),
        starts_at: form.start,
        ends_at: form.end,
        frequency: form.frequency,
        until: form.frequency === 'none' ? null : form.until || null,
        reminder_minutes:
            form.reminder === 'none' ? null : Number(form.reminder),
    };
}

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    processing.value = true;
    const options = {
        preserveScroll: true,
        onError: (e: Record<string, string>) => {
            errors.value = e;
        },
        onSuccess: () => close(),
        onFinish: () => {
            processing.value = false;
        },
    };

    if (props.event) {
        router.patch(update(props.event.id).url, payload(), options);
    } else {
        router.post(store().url, payload(), options);
    }
}

function remove(): void {
    if (!props.event) {
        return;
    }

    processing.value = true;
    router.delete(destroy(props.event.id).url, {
        preserveScroll: true,
        onSuccess: () => close(),
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{
                    event ? 'Edit event' : 'New event'
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        event
                            ? 'Update the details of this event.'
                            : 'Add an event to your calendar.'
                    }}
                </SheetDescription>
            </SheetHeader>

            <form
                class="flex flex-1 flex-col gap-4 overflow-y-auto px-4 py-2"
                @submit.prevent="submit"
            >
                <a
                    v-if="source"
                    :href="source.href"
                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm text-primary hover:bg-accent"
                >
                    <ExternalLink class="size-4" />
                    {{ source.label }}
                </a>

                <div class="grid gap-2">
                    <Label for="title">Title</Label>
                    <Input id="title" v-model="form.title" required autofocus />
                    <p v-if="errors.title" class="text-sm text-destructive">
                        {{ errors.title }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="calendar">Calendar</Label>
                    <select
                        id="calendar"
                        v-model="form.calendar_id"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="calendar in calendars"
                            :key="calendar.id"
                            :value="calendar.id"
                        >
                            {{ calendar.name }}
                        </option>
                    </select>
                    <p
                        v-if="errors.calendar_id"
                        class="text-sm text-destructive"
                    >
                        {{ errors.calendar_id }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.all_day"
                        type="checkbox"
                        class="size-4"
                    />
                    All day
                </label>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label for="start">Starts</Label>
                        <Input
                            id="start"
                            v-model="form.start"
                            :type="form.all_day ? 'date' : 'datetime-local'"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end">Ends</Label>
                        <Input
                            id="end"
                            v-model="form.end"
                            :type="form.all_day ? 'date' : 'datetime-local'"
                            required
                        />
                    </div>
                </div>
                <p v-if="errors.ends_at" class="text-sm text-destructive">
                    {{ errors.ends_at }}
                </p>

                <div class="grid gap-2">
                    <Label for="repeat">Repeat</Label>
                    <select
                        id="repeat"
                        v-model="form.frequency"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="option in repeatOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="form.frequency !== 'none'" class="grid gap-2">
                    <Label for="until">Until (optional)</Label>
                    <Input id="until" v-model="form.until" type="date" />
                    <p v-if="errors.until" class="text-sm text-destructive">
                        {{ errors.until }}
                    </p>
                </div>

                <div v-if="!form.all_day" class="grid gap-2">
                    <Label for="reminder">Reminder</Label>
                    <select
                        id="reminder"
                        v-model="form.reminder"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="option in reminderOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="grid gap-2">
                    <Label for="location">Location</Label>
                    <Input id="location" v-model="form.location" />
                </div>

                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="3"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                    />
                </div>
            </form>

            <SheetFooter class="flex-row justify-between gap-2">
                <Button
                    v-if="event"
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="remove"
                >
                    Delete
                </Button>
                <div class="ml-auto flex gap-2">
                    <Button type="button" variant="outline" @click="close">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="processing"
                        @click="submit"
                    >
                        Save
                    </Button>
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
