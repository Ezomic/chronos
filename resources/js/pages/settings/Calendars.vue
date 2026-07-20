<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    Eye,
    EyeOff,
    Lock,
    Pencil,
    Plug,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/Settings/CalendarController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { cn } from '@/lib/utils';
import { edit as editCalendars, visibility } from '@/routes/calendars';
import { destroy as disconnectAccount } from '@/routes/connected-accounts';
import { redirect as oauthRedirect } from '@/routes/oauth';

interface ManagedCalendar {
    id: number;
    name: string;
    color: string;
    is_default: boolean;
    is_writable: boolean;
    is_visible: boolean;
    provider: string | null;
    account_email: string | null;
}

interface ConnectedAccount {
    id: number;
    provider: string;
    email: string;
    display_name: string | null;
    sync_status: string;
    last_synced_at_diff: string | null;
}

const props = defineProps<{
    calendars: ManagedCalendar[];
    palette: string[];
    accounts: ConnectedAccount[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendars', href: editCalendars() }],
    },
});

const myCalendars = computed(() =>
    props.calendars.filter((c) => c.is_writable),
);
const subscribed = computed(() =>
    props.calendars.filter((c) => !c.is_writable),
);

const providers = [
    { key: 'google', label: 'Google Calendar' },
    { key: 'microsoft', label: 'Microsoft (Outlook)' },
];
const providerLabel = (key: string | null) =>
    providers.find((p) => p.key === key)?.label ?? key ?? 'Subscribed';

const swatch = (active: boolean) =>
    cn(
        'size-7 rounded-full transition',
        active
            ? 'ring-2 ring-ring ring-offset-2 ring-offset-background'
            : 'hover:scale-110',
    );

// Create
const createOpen = ref(false);
const createName = ref('');
const createColor = ref(props.palette[0]);
function openCreate(): void {
    createName.value = '';
    createColor.value = props.palette[0];
    createOpen.value = true;
}

// Edit
const editOpen = ref(false);
const editing = ref<ManagedCalendar | null>(null);
const editName = ref('');
const editColor = ref('');
function openEdit(calendar: ManagedCalendar): void {
    editing.value = calendar;
    editName.value = calendar.name;
    editColor.value = calendar.color;
    editOpen.value = true;
}

// Delete
const deleteOpen = ref(false);
const deleting = ref<ManagedCalendar | null>(null);
function openDelete(calendar: ManagedCalendar): void {
    deleting.value = calendar;
    deleteOpen.value = true;
}

function toggleVisibility(calendar: ManagedCalendar): void {
    router.patch(
        visibility(calendar.id).url,
        { is_visible: !calendar.is_visible },
        { preserveScroll: true, preserveState: true },
    );
}

function disconnect(id: number): void {
    router.delete(disconnectAccount(id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Calendars" />

    <div class="space-y-10">
        <!-- Local calendars -->
        <section class="space-y-4">
            <div class="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Your calendars"
                    description="Calendars you own. Events created in Chronos land here."
                />
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" />
                    New calendar
                </Button>
            </div>

            <div class="space-y-2">
                <div
                    v-for="calendar in myCalendars"
                    :key="calendar.id"
                    class="flex items-center gap-3 rounded-lg border p-3"
                >
                    <span
                        class="size-4 shrink-0 rounded-full"
                        :style="{ backgroundColor: calendar.color }"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ calendar.name }}
                            <span
                                v-if="calendar.is_default"
                                class="ml-1 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Default
                            </span>
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="calendar.is_visible ? 'Hide' : 'Show'"
                        @click="toggleVisibility(calendar)"
                    >
                        <Eye v-if="calendar.is_visible" class="size-4" />
                        <EyeOff v-else class="size-4 text-muted-foreground" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Edit"
                        @click="openEdit(calendar)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        v-if="!calendar.is_default"
                        variant="ghost"
                        size="icon"
                        aria-label="Delete"
                        @click="openDelete(calendar)"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </Button>
                </div>
            </div>
        </section>

        <!-- Subscribed (mirrored, read-only) -->
        <section v-if="subscribed.length" class="space-y-4">
            <Heading
                variant="small"
                title="Subscribed calendars"
                description="Mirrored from connected accounts. Read-only; you can hide them."
            />
            <div class="space-y-2">
                <div
                    v-for="calendar in subscribed"
                    :key="calendar.id"
                    class="flex items-center gap-3 rounded-lg border p-3"
                >
                    <span
                        class="size-4 shrink-0 rounded-full"
                        :style="{ backgroundColor: calendar.color }"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ calendar.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ providerLabel(calendar.provider) }}
                            <span v-if="calendar.account_email">
                                · {{ calendar.account_email }}
                            </span>
                        </p>
                    </div>
                    <Lock class="size-4 shrink-0 text-muted-foreground" />
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="calendar.is_visible ? 'Hide' : 'Show'"
                        @click="toggleVisibility(calendar)"
                    >
                        <Eye v-if="calendar.is_visible" class="size-4" />
                        <EyeOff v-else class="size-4 text-muted-foreground" />
                    </Button>
                </div>
            </div>
        </section>

        <!-- Connected accounts -->
        <section class="space-y-4">
            <Heading
                variant="small"
                title="Connected accounts"
                description="Show your Google and Microsoft calendars in Chronos."
            />

            <div v-if="accounts.length" class="space-y-2">
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="flex items-center justify-between rounded-lg border p-3"
                >
                    <div class="flex items-center gap-3">
                        <CalendarDays class="size-5 text-muted-foreground" />
                        <div>
                            <p class="text-sm font-medium">
                                {{ account.email }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ providerLabel(account.provider) }}
                                <span v-if="account.last_synced_at_diff">
                                    · synced {{ account.last_synced_at_diff }}
                                </span>
                                <span v-else>· not synced yet</span>
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="disconnect(account.id)"
                    >
                        Disconnect
                    </Button>
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No accounts connected yet.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="provider in providers"
                    :key="provider.key"
                    variant="outline"
                    as-child
                >
                    <a :href="oauthRedirect(provider.key).url">
                        <Plug class="size-4" />
                        {{ provider.label }}
                    </a>
                </Button>
            </div>
        </section>
    </div>

    <!-- Create dialog -->
    <Dialog v-model:open="createOpen">
        <DialogContent>
            <Form
                v-bind="CalendarController.store.form()"
                :options="{ preserveScroll: true }"
                @success="createOpen = false"
                class="space-y-5"
                v-slot="{ processing, errors }"
            >
                <DialogHeader>
                    <DialogTitle>New calendar</DialogTitle>
                    <DialogDescription>
                        Create a calendar to organize your events.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="create-name">Name</Label>
                    <Input
                        id="create-name"
                        name="name"
                        v-model="createName"
                        placeholder="e.g. Work"
                        autocomplete="off"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label>Color</Label>
                    <input type="hidden" name="color" :value="createColor" />
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="color in palette"
                            :key="color"
                            type="button"
                            :class="swatch(createColor === color)"
                            :style="{ backgroundColor: color }"
                            :aria-label="`Pick ${color}`"
                            @click="createColor = color"
                        />
                    </div>
                    <InputError :message="errors.color" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        Create calendar
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <!-- Edit dialog -->
    <Dialog v-model:open="editOpen">
        <DialogContent v-if="editing">
            <Form
                v-bind="CalendarController.update.form(editing.id)"
                :options="{ preserveScroll: true }"
                @success="editOpen = false"
                class="space-y-5"
                v-slot="{ processing, errors }"
            >
                <DialogHeader>
                    <DialogTitle>Edit calendar</DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-name">Name</Label>
                    <Input
                        id="edit-name"
                        name="name"
                        v-model="editName"
                        autocomplete="off"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label>Color</Label>
                    <input type="hidden" name="color" :value="editColor" />
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="color in palette"
                            :key="color"
                            type="button"
                            :class="swatch(editColor === color)"
                            :style="{ backgroundColor: color }"
                            :aria-label="`Pick ${color}`"
                            @click="editColor = color"
                        />
                    </div>
                    <InputError :message="errors.color" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        Save changes
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <!-- Delete dialog -->
    <Dialog v-model:open="deleteOpen">
        <DialogContent v-if="deleting">
            <Form
                v-bind="CalendarController.destroy.form(deleting.id)"
                :options="{ preserveScroll: true }"
                @success="deleteOpen = false"
                class="space-y-5"
                v-slot="{ processing }"
            >
                <DialogHeader>
                    <DialogTitle>Delete “{{ deleting.name }}”?</DialogTitle>
                    <DialogDescription>
                        This calendar and all of its events will be permanently
                        removed. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        Delete calendar
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
