<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarClock, Pencil, Plus, Trash2 } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { reminderOptions, repeatOptions } from '@/lib/eventOptions';
import {
    destroy as destroyTemplate,
    edit as editTemplates,
    store as storeTemplate,
    update as updateTemplate,
} from '@/routes/event-templates';
import type { EventTemplate, WritableCalendar } from '@/types/calendar';

const props = defineProps<{
    templates: EventTemplate[];
    calendars: WritableCalendar[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Templates', href: editTemplates() }],
    },
});

interface FormState {
    name: string;
    calendar_id: number | null;
    title: string;
    description: string;
    location: string;
    all_day: boolean;
    durationDays: number;
    durationHours: number;
    durationMinutes: number;
    default_start_time: string;
    frequency: string;
    reminder: string;
}

const form = reactive<FormState>({
    name: '',
    calendar_id: null,
    title: '',
    description: '',
    location: '',
    all_day: false,
    durationDays: 1,
    durationHours: 1,
    durationMinutes: 0,
    default_start_time: '09:00',
    frequency: 'none',
    reminder: 'none',
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);
const dialogOpen = ref(false);
const editing = ref<EventTemplate | null>(null);

const deleteOpen = ref(false);
const deleting = ref<EventTemplate | null>(null);

// Reminders are only offered for timed events, so drop a stale one when the
// template is switched to all-day (the reminder field is hidden then).
watch(
    () => form.all_day,
    (allDay) => {
        if (allDay) {
            form.reminder = 'none';
        }
    },
);

const defaultCalendarId = () =>
    props.calendars.find((c) => c.is_default)?.id ??
    props.calendars[0]?.id ??
    null;

function reset(): void {
    form.name = '';
    form.calendar_id = defaultCalendarId();
    form.title = '';
    form.description = '';
    form.location = '';
    form.all_day = false;
    form.durationDays = 1;
    form.durationHours = 1;
    form.durationMinutes = 0;
    form.default_start_time = '09:00';
    form.frequency = 'none';
    form.reminder = 'none';
    errors.value = {};
}

function openCreate(): void {
    editing.value = null;
    reset();
    dialogOpen.value = true;
}

function openEdit(template: EventTemplate): void {
    editing.value = template;
    errors.value = {};
    form.name = template.name;
    form.calendar_id = template.calendar_id ?? defaultCalendarId();
    form.title = template.title;
    form.description = template.description ?? '';
    form.location = template.location ?? '';
    form.all_day = template.all_day;
    form.default_start_time = template.default_start_time ?? '09:00';
    form.frequency = template.frequency ?? 'none';
    form.reminder =
        template.reminder_minutes === null
            ? 'none'
            : String(template.reminder_minutes);

    if (template.all_day) {
        form.durationDays = Math.max(
            1,
            Math.round(template.duration_minutes / 1440),
        );
        form.durationHours = 1;
        form.durationMinutes = 0;
    } else {
        form.durationDays = 1;
        form.durationHours = Math.floor(template.duration_minutes / 60);
        form.durationMinutes = template.duration_minutes % 60;
    }

    dialogOpen.value = true;
}

function payload() {
    const duration = form.all_day
        ? Math.max(1, form.durationDays) * 1440
        : Math.max(1, form.durationHours * 60 + form.durationMinutes);

    return {
        name: form.name,
        calendar_id: form.calendar_id,
        title: form.title,
        description: form.description || null,
        location: form.location || null,
        all_day: form.all_day,
        duration_minutes: duration,
        default_start_time: form.all_day
            ? null
            : form.default_start_time || null,
        frequency: form.frequency === 'none' ? null : form.frequency,
        reminder_minutes:
            form.reminder === 'none' ? null : Number(form.reminder),
    };
}

function submit(): void {
    processing.value = true;
    const options = {
        preserveScroll: true,
        onError: (e: Record<string, string>) => {
            errors.value = e;
        },
        onSuccess: () => {
            dialogOpen.value = false;
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    if (editing.value) {
        router.patch(updateTemplate(editing.value.id).url, payload(), options);
    } else {
        router.post(storeTemplate().url, payload(), options);
    }
}

function openDelete(template: EventTemplate): void {
    deleting.value = template;
    deleteOpen.value = true;
}

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    router.delete(destroyTemplate(deleting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}

const calendarName = (id: number | null) =>
    props.calendars.find((c) => c.id === id)?.name ?? 'Default calendar';

function summary(template: EventTemplate): string {
    const parts: string[] = [];

    if (template.all_day) {
        const days = Math.max(1, Math.round(template.duration_minutes / 1440));
        parts.push(days === 1 ? 'All day' : `All day, ${days} days`);
    } else {
        const h = Math.floor(template.duration_minutes / 60);
        const m = template.duration_minutes % 60;
        const length = [h ? `${h}h` : '', m ? `${m}m` : '']
            .filter(Boolean)
            .join(' ');
        parts.push(template.default_start_time ?? 'No default time');

        if (length) {
            parts.push(length);
        }
    }

    parts.push(calendarName(template.calendar_id));

    return parts.join(' · ');
}
</script>

<template>
    <Head title="Templates" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Event templates"
                description="Save an event's setup once and reuse it when creating new events."
            />
            <Button size="sm" @click="openCreate">
                <Plus class="size-4" />
                New template
            </Button>
        </div>

        <div v-if="templates.length" class="space-y-2">
            <div
                v-for="template in templates"
                :key="template.id"
                class="flex items-center gap-3 rounded-lg border p-3"
            >
                <CalendarClock class="size-5 shrink-0 text-muted-foreground" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ template.name }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ summary(template) }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Edit"
                    @click="openEdit(template)"
                >
                    <Pencil class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Delete"
                    @click="openDelete(template)"
                >
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            No templates yet. Create one here, or use “Save as template” while
            building an event.
        </p>
    </div>

    <!-- Create / edit dialog -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto">
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? 'Edit template' : 'New template' }}
                    </DialogTitle>
                    <DialogDescription>
                        Everything except the specific date is saved.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="name">Template name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="e.g. Weekly 1:1"
                        autocomplete="off"
                        required
                    />
                    <p v-if="errors.name" class="text-sm text-destructive">
                        {{ errors.name }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="title">Event title</Label>
                    <Input id="title" v-model="form.title" required />
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

                <div v-if="form.all_day" class="grid gap-2">
                    <Label for="days">Duration (days)</Label>
                    <Input
                        id="days"
                        v-model.number="form.durationDays"
                        type="number"
                        min="1"
                    />
                </div>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="start-time">Default start time</Label>
                        <Input
                            id="start-time"
                            v-model="form.default_start_time"
                            type="time"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-2">
                            <Label for="hours">Duration (hours)</Label>
                            <Input
                                id="hours"
                                v-model.number="form.durationHours"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="minutes">Minutes</Label>
                            <Input
                                id="minutes"
                                v-model.number="form.durationMinutes"
                                type="number"
                                min="0"
                                max="59"
                            />
                        </div>
                    </div>
                </template>
                <p
                    v-if="errors.duration_minutes"
                    class="text-sm text-destructive"
                >
                    {{ errors.duration_minutes }}
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

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        {{ editing ? 'Save changes' : 'Create template' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Delete dialog -->
    <Dialog v-model:open="deleteOpen">
        <DialogContent v-if="deleting">
            <DialogHeader>
                <DialogTitle>Delete “{{ deleting.name }}”?</DialogTitle>
                <DialogDescription>
                    This template will be permanently removed. Existing events
                    are not affected.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary" type="button">Cancel</Button>
                </DialogClose>
                <Button
                    type="button"
                    variant="destructive"
                    @click="confirmDelete"
                >
                    Delete template
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
