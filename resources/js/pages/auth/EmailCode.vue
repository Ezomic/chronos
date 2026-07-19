<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/login/code';

defineOptions({
    layout: {
        title: 'Log in with a code',
        description: "Enter your email and we'll send you a one-time code",
    },
});
</script>

<template>
    <Head title="Log in with a code" />

    <div class="space-y-6">
        <Form v-bind="store.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    Send login code
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Or, return to</span>
            <TextLink :href="login()">the other sign-in options</TextLink>
        </div>
    </div>
</template>
