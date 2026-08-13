<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { edit } from '@/routes/profile';
import { destroy as destroyPushSubscription } from '@/routes/push-subscriptions';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

interface PushDevice {
    id: number;
    label: string;
    added_at_diff: string;
    last_used_at_diff: string | null;
}

const props = defineProps<{
    timezones: string[];
    vapidPublicKey: string;
    pushDevices: PushDevice[];
}>();

const push = usePushNotifications(props.vapidPublicKey);

function removeDevice(id: number): void {
    router.delete(destroyPushSubscription(id).url, { preserveScroll: true });
}

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name, email address and timezone"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <Select
                    name="timezone"
                    :default-value="user.timezone as string"
                >
                    <SelectTrigger id="timezone" class="mt-1 w-full">
                        <SelectValue placeholder="Select a timezone" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="zone in timezones"
                            :key="zone"
                            :value="zone"
                        >
                            {{ zone.replace(/_/g, ' ') }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">
                    New events default to this timezone, and so do events other
                    apps create for you without naming one.
                </p>
                <InputError class="mt-2" :message="errors.timezone" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <div class="mt-10 flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Reminder notifications"
            description="Send event reminders to this device instead of your inbox"
        />

        <div class="space-y-4">
            <p class="text-sm text-muted-foreground">
                Reminders go to your devices when at least one is listening, and
                to email when none is.
            </p>

            <div v-if="pushDevices.length" class="divide-y rounded-md border">
                <div
                    v-for="device in pushDevices"
                    :key="device.id"
                    class="flex items-center justify-between gap-4 p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ device.label }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Added {{ device.added_at_diff
                            }}<template v-if="device.last_used_at_diff">
                                , last used {{ device.last_used_at_diff }}
                            </template>
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="removeDevice(device.id)"
                    >
                        Remove
                    </Button>
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No devices yet. Reminders are going to your email.
            </p>

            <Button
                type="button"
                :disabled="!push.supported.value || push.busy.value"
                @click="push.enable()"
            >
                {{ push.busy.value ? 'Enabling...' : 'Enable on this device' }}
            </Button>

            <p
                v-if="!push.supported.value"
                class="text-sm text-muted-foreground"
            >
                This browser cannot receive push notifications, or the server
                has no VAPID keys configured.
            </p>
            <p v-if="push.error.value" class="text-sm text-destructive">
                {{ push.error.value }}
            </p>
        </div>
    </div>

    <DeleteUser />
</template>
