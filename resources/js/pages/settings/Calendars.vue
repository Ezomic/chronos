<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, Plug } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit as editCalendars, destroy } from '@/routes/calendars';
import { redirect as oauthRedirect } from '@/routes/oauth';

interface ConnectedAccount {
    id: number;
    provider: string;
    email: string;
    display_name: string | null;
    sync_status: string;
    last_synced_at_diff: string | null;
}

defineProps<{ accounts: ConnectedAccount[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendar accounts', href: editCalendars() }],
    },
});

const providers = [
    { key: 'google', label: 'Google Calendar' },
    { key: 'microsoft', label: 'Microsoft (Outlook)' },
];

const providerLabel = (key: string) =>
    providers.find((p) => p.key === key)?.label ?? key;

function disconnect(id: number): void {
    router.delete(destroy(id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Calendar accounts" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Connected calendars"
            description="Show your Google and Microsoft calendars in Chronos (read-only)."
        />

        <div v-if="accounts.length" class="space-y-2">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex items-center justify-between rounded-lg border p-4"
            >
                <div class="flex items-center gap-3">
                    <CalendarDays class="size-5 text-muted-foreground" />
                    <div>
                        <p class="text-sm font-medium">{{ account.email }}</p>
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
            No calendars connected yet.
        </p>

        <div class="space-y-2">
            <p class="text-sm font-medium">Connect a calendar</p>
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
        </div>
    </div>
</template>
