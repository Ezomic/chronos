<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { authenticate, create } from '@/routes/login/code';

const props = defineProps<{
    email: string;
}>();

defineOptions({
    layout: {
        title: 'Enter your code',
        description: 'Check your email for the 6-digit code',
    },
});
</script>

<template>
    <Head title="Enter your code" />

    <div class="space-y-6">
        <p class="text-center text-sm text-muted-foreground">
            Sent to
            <span class="font-medium text-foreground">{{ props.email }}</span>
        </p>

        <Form
            v-bind="authenticate.form()"
            reset-on-error
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="code">Login code</Label>
                <Input
                    id="code"
                    name="code"
                    inputmode="numeric"
                    maxlength="6"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    placeholder="123456"
                />
                <InputError :message="errors.code" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Didn't get a code?</span>
            <TextLink :href="create()">Try again</TextLink>
        </div>
    </div>
</template>
