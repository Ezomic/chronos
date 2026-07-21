<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    getLocalTimeZone,
    parseAbsolute,
    toCalendarDate,
} from '@internationalized/date';
import { ExternalLink } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import TimeSelect from '@/components/TimeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { reminderOptions, repeatOptions } from '@/lib/eventOptions';
import { sourceLink } from '@/lib/eventSource';
import { store as storeTemplate } from '@/routes/event-templates';
import { destroy, store, update } from '@/routes/events';
import type {
    CalendarEvent,
    EventTemplate,
    WritableCalendar,
} from '@/types/calendar';

const props = defineProps<{
    open: boolean;
    event: CalendarEvent | null;
    defaultDate: string | null;
    calendars: WritableCalendar[];
    templates: EventTemplate[];
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

const selectedTemplateId = ref<number | null>(null);

const saveOpen = ref(false);
const templateName = ref('');
const templateError = ref('');
const savingTemplate = ref(false);

const source = computed(() => (props.event ? sourceLink(props.event) : null));

// Events on read-only mirrored calendars (Google/Microsoft) can't be edited,
// and their calendar isn't in the writable list the form's picker is built
// from, so they're shown as a read-only detail view instead.
const readOnly = computed(() => !!props.event && !props.event.editable);

const pad = (n: number) => String(n).padStart(2, '0');

const whenText = computed(() => {
    if (!props.event) {
        return '';
    }

    const fmtDate = (s: string) => {
        const [y, m, d] = s.slice(0, 10).split('-').map(Number);

        return new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(y, m - 1, d));
    };
    const time = (s: string) => (s.length >= 16 ? s.slice(11, 16) : '');
    const sameDay = form.start.slice(0, 10) === form.end.slice(0, 10);

    if (form.all_day) {
        return sameDay
            ? fmtDate(form.start)
            : `${fmtDate(form.start)} – ${fmtDate(form.end)}`;
    }

    return sameDay
        ? `${fmtDate(form.start)}, ${time(form.start)} – ${time(form.end)}`
        : `${fmtDate(form.start)} ${time(form.start)} – ${fmtDate(form.end)} ${time(form.end)}`;
});

function localDateTime(iso: string, timezone: string): string {
    const z = parseAbsolute(iso, timezone);

    return `${z.year}-${pad(z.month)}-${pad(z.day)}T${pad(z.hour)}:${pad(z.minute)}`;
}

function hydrate(): void {
    errors.value = {};
    selectedTemplateId.value = null;
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
    form.all_day = false;
    form.start = `${date}T09:00`;
    form.end = `${date}T10:00`;
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

// Split the combined "YYYY-MM-DDTHH:MM" start/end into a native date input and
// a locale-independent 24-hour TimeSelect.
const timePart = (dt: string) => (dt.length >= 16 ? dt.slice(11, 16) : '09:00');

const startDate = computed({
    get: () => form.start.slice(0, 10),
    set: (v: string) => {
        form.start = form.all_day ? v : `${v}T${timePart(form.start)}`;
    },
});
const startTime = computed({
    get: () => timePart(form.start),
    set: (v: string) => {
        form.start = `${form.start.slice(0, 10)}T${v}`;
    },
});
const endDate = computed({
    get: () => form.end.slice(0, 10),
    set: (v: string) => {
        form.end = form.all_day ? v : `${v}T${timePart(form.end)}`;
    },
});
const endTime = computed({
    get: () => timePart(form.end),
    set: (v: string) => {
        form.end = `${form.end.slice(0, 10)}T${v}`;
    },
});

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

function formatLocal(d: Date, withTime: boolean): string {
    const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    return withTime
        ? `${date}T${pad(d.getHours())}:${pad(d.getMinutes())}`
        : date;
}

function applyTemplate(template: EventTemplate): void {
    const date = props.defaultDate ?? new Date().toISOString().slice(0, 10);
    const [y, m, dd] = date.split('-').map(Number);

    form.calendar_id =
        template.calendar_id ??
        props.calendars.find((c) => c.is_default)?.id ??
        props.calendars[0]?.id ??
        null;
    form.title = template.title;
    form.description = template.description ?? '';
    form.location = template.location ?? '';
    form.all_day = template.all_day;
    form.frequency = template.frequency ?? 'none';
    form.until = '';
    form.reminder =
        template.reminder_minutes === null
            ? 'none'
            : String(template.reminder_minutes);

    if (template.all_day) {
        // duration is whole days; the end input is the inclusive last day.
        const days = Math.max(1, Math.round(template.duration_minutes / 1440));
        form.start = formatLocal(new Date(y, m - 1, dd), false);
        form.end = formatLocal(new Date(y, m - 1, dd + days - 1), false);
    } else {
        const [h, min] = (template.default_start_time ?? '09:00')
            .split(':')
            .map(Number);
        const start = new Date(y, m - 1, dd, h, min);
        const end = new Date(
            start.getTime() + template.duration_minutes * 60000,
        );
        form.start = formatLocal(start, true);
        form.end = formatLocal(end, true);
    }
}

function onTemplateChange(id: number | null): void {
    selectedTemplateId.value = id;
    const template = props.templates.find((t) => t.id === id);

    if (template) {
        applyTemplate(template);
    }
}

function templatePayload() {
    let durationMinutes: number;

    if (form.all_day) {
        const start = new Date(`${form.start.slice(0, 10)}T00:00`).getTime();
        const end = new Date(`${form.end.slice(0, 10)}T00:00`).getTime();
        // The end input is the inclusive last day, so it counts as a full day.
        durationMinutes = Math.round((end - start) / 60000) + 1440;
    } else {
        const start = new Date(form.start).getTime();
        const end = new Date(form.end).getTime();
        durationMinutes = Math.round((end - start) / 60000);
    }

    return {
        name: templateName.value,
        calendar_id: form.calendar_id,
        title: form.title,
        description: form.description || null,
        location: form.location || null,
        all_day: form.all_day,
        duration_minutes: durationMinutes,
        default_start_time: form.all_day ? null : form.start.slice(11, 16),
        frequency: form.frequency === 'none' ? null : form.frequency,
        reminder_minutes:
            form.reminder === 'none' ? null : Number(form.reminder),
    };
}

function openSaveTemplate(): void {
    templateName.value = form.title;
    templateError.value = '';
    saveOpen.value = true;
}

function saveTemplate(): void {
    savingTemplate.value = true;
    router.post(storeTemplate().url, templatePayload(), {
        preserveScroll: true,
        preserveState: true,
        onError: (e: Record<string, string>) => {
            templateError.value =
                Object.values(e)[0] ?? 'Could not save template.';
        },
        onSuccess: () => {
            saveOpen.value = false;
        },
        onFinish: () => {
            savingTemplate.value = false;
        },
    });
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
                    readOnly ? 'Event' : event ? 'Edit event' : 'New event'
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        readOnly
                            ? 'Synced from a connected calendar. Read-only.'
                            : event
                              ? 'Update the details of this event.'
                              : 'Add an event to your calendar.'
                    }}
                </SheetDescription>
            </SheetHeader>

            <div
                v-if="readOnly && event"
                class="flex flex-1 flex-col gap-5 overflow-y-auto px-4 py-4"
            >
                <div class="flex items-start gap-2">
                    <span
                        class="mt-1 size-3 shrink-0 rounded-full"
                        :style="{ backgroundColor: event.color }"
                    />
                    <div class="min-w-0">
                        <p class="text-base leading-tight font-medium">
                            {{ event.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ event.calendar_name }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-1">
                    <span
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        When
                    </span>
                    <p class="text-sm">{{ whenText }}</p>
                </div>

                <div v-if="event.location" class="grid gap-1">
                    <span
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Location
                    </span>
                    <p class="text-sm">{{ event.location }}</p>
                </div>

                <div v-if="event.description" class="grid gap-1">
                    <span
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Description
                    </span>
                    <p class="text-sm whitespace-pre-wrap">
                        {{ event.description }}
                    </p>
                </div>
            </div>

            <form
                v-else
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

                <div v-if="!event && templates.length" class="grid gap-2">
                    <Label for="template">Start from template</Label>
                    <select
                        id="template"
                        :value="selectedTemplateId ?? ''"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        @change="
                            onTemplateChange(
                                ($event.target as HTMLSelectElement).value
                                    ? Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      )
                                    : null,
                            )
                        "
                    >
                        <option value="">Blank event</option>
                        <option
                            v-for="template in templates"
                            :key="template.id"
                            :value="template.id"
                        >
                            {{ template.name }}
                        </option>
                    </select>
                </div>

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
                            v-model="startDate"
                            type="date"
                            required
                        />
                        <TimeSelect v-if="!form.all_day" v-model="startTime" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end">Ends</Label>
                        <Input
                            id="end"
                            v-model="endDate"
                            type="date"
                            required
                        />
                        <TimeSelect v-if="!form.all_day" v-model="endTime" />
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

            <SheetFooter v-if="readOnly" class="flex-row justify-end">
                <Button type="button" variant="outline" @click="close">
                    Close
                </Button>
            </SheetFooter>
            <SheetFooter v-else class="flex-row justify-between gap-2">
                <Button
                    v-if="event"
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="remove"
                >
                    Delete
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    :disabled="processing || !form.title"
                    @click="openSaveTemplate"
                >
                    Save as template
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

    <Dialog v-model:open="saveOpen">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="saveTemplate">
                <DialogHeader>
                    <DialogTitle>Save as template</DialogTitle>
                    <DialogDescription>
                        Reuse this event's setup later without re-entering every
                        field. The date is not stored.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="template-name">Template name</Label>
                    <Input
                        id="template-name"
                        v-model="templateName"
                        placeholder="e.g. Weekly 1:1"
                        autocomplete="off"
                        required
                        autofocus
                    />
                    <p v-if="templateError" class="text-sm text-destructive">
                        {{ templateError }}
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="savingTemplate || !templateName"
                    >
                        Save template
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
