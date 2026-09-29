<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, store } from '@/routes/api-tokens';
import type { ApiAbilityOption, ApiToken } from '@/types';

defineProps<{
    tokens: ApiToken[];
    abilityOptions: ApiAbilityOption[];
    appOptions: string[];
    createdToken: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

const form = useForm<{ name: string; abilities: string[]; app: string | null }>(
    {
        name: '',
        abilities: ['events:create'],
        app: null,
    },
);

function create(): void {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
}

const copied = ref(false);

async function copy(token: string): Promise<void> {
    await navigator.clipboard.writeText(token);
    copied.value = true;

    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}

const revokeOpen = ref(false);
const revoking = ref<ApiToken | null>(null);

function openRevoke(token: ApiToken): void {
    revoking.value = token;
    revokeOpen.value = true;
}

function confirmRevoke(): void {
    if (!revoking.value) {
        return;
    }

    router.delete(destroy(revoking.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            revokeOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Tokens let another app, such as zero or tempo, create events in your calendars as you. Treat them like passwords."
        />

        <!-- Rendered from a session flash, so it is here for exactly one render.
             Only a hash is stored, so this is the only chance to copy it. -->
        <Alert v-if="createdToken">
            <AlertTitle>Copy your token now</AlertTitle>
            <AlertDescription class="min-w-0 space-y-3">
                <p>This is the only time it will be shown.</p>
                <div class="flex w-full min-w-0 items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-xs"
                        data-testid="created-token"
                    >
                        {{ createdToken }}
                    </code>
                    <Button
                        size="sm"
                        variant="outline"
                        type="button"
                        :aria-label="copied ? 'Copied' : 'Copy token'"
                        @click="copy(createdToken)"
                    >
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

        <form class="space-y-5 rounded-lg border p-4" @submit.prevent="create">
            <div class="grid gap-2">
                <Label for="name">Token name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    placeholder="e.g. zero"
                    autocomplete="off"
                    required
                />
                <InputError :message="form.errors.name" />
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-3 text-sm font-medium">Abilities</legend>
                <label
                    v-for="ability in abilityOptions"
                    :key="ability.value"
                    class="flex items-start gap-2 text-sm"
                >
                    <input
                        v-model="form.abilities"
                        type="checkbox"
                        :value="ability.value"
                        class="mt-0.5 size-4"
                    />
                    <span>
                        <code class="font-mono text-xs">{{
                            ability.value
                        }}</code>
                        <span class="block text-muted-foreground">
                            {{ ability.description }}
                        </span>
                    </span>
                </label>
                <InputError :message="form.errors.abilities" />
            </fieldset>

            <div class="grid gap-2">
                <Label for="app">App</Label>
                <select
                    id="app"
                    v-model="form.app"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                >
                    <option :value="null">No app</option>
                    <option v-for="app in appOptions" :key="app" :value="app">
                        {{ app }}
                    </option>
                </select>
                <p class="text-sm text-muted-foreground">
                    The app this token speaks for. Managing events needs one,
                    and it stops the token creating events for any other app.
                </p>
                <InputError :message="form.errors.app" />
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Create token
            </Button>
        </form>

        <div class="overflow-hidden rounded-lg border">
            <p
                v-if="tokens.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No tokens yet.
            </p>

            <div
                v-for="token in tokens"
                v-else
                :key="token.id"
                class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
            >
                <div class="min-w-0 space-y-1.5">
                    <p class="truncate font-medium">{{ token.name }}</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="ability in token.abilities"
                            :key="ability"
                            variant="secondary"
                            class="font-mono"
                        >
                            {{ ability }}
                        </Badge>
                        <Badge v-if="token.app" variant="outline">
                            {{ token.app }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Created {{ token.created_at_diff }} &middot;
                        {{
                            token.last_used_at_diff
                                ? `last used ${token.last_used_at_diff}`
                                : 'never used'
                        }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    :aria-label="`Revoke ${token.name}`"
                    @click="openRevoke(token)"
                >
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>
    </div>

    <Dialog v-model:open="revokeOpen">
        <DialogContent v-if="revoking">
            <DialogHeader>
                <DialogTitle>Revoke “{{ revoking.name }}”?</DialogTitle>
                <DialogDescription>
                    Anything using this token stops working straight away. This
                    cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary" type="button">Cancel</Button>
                </DialogClose>
                <Button
                    type="button"
                    variant="destructive"
                    @click="confirmRevoke"
                >
                    Revoke token
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
