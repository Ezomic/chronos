<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarCheck, CalendarX } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/book';
import type { BookingCopy, BookingLocale } from '@/types';

const props = defineProps<{
    locale: BookingLocale;
    copy: BookingCopy;
    booking: {
        name: string;
        email: string;
        type: string;
        starts_at: string;
        timezone: string;
        cancelled: boolean;
    };
    cancel_url: string | null;
}>();

const processing = ref(false);

const when = computed(() =>
    new Intl.DateTimeFormat(props.locale, {
        timeZone: props.booking.timezone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZoneName: 'short',
    }).format(new Date(props.booking.starts_at)),
);

function cancel(): void {
    if (!props.cancel_url) {
        return;
    }

    router.post(
        props.cancel_url,
        {},
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head
        :title="
            booking.cancelled ? copy.cancelled_heading : copy.confirmed_heading
        "
    />

    <div class="min-h-screen bg-background px-4 py-8 sm:py-14">
        <div class="mx-auto w-full max-w-md">
            <div
                class="mb-4 flex size-10 items-center justify-center rounded-full"
                :class="
                    booking.cancelled
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-primary/10 text-primary'
                "
            >
                <CalendarX v-if="booking.cancelled" class="size-5" />
                <CalendarCheck v-else class="size-5" />
            </div>

            <h1 class="text-2xl font-semibold tracking-tight">
                {{
                    booking.cancelled
                        ? copy.cancelled_heading
                        : copy.confirmed_heading
                }}
            </h1>

            <p
                class="mt-4 font-medium"
                :class="{ 'line-through': booking.cancelled }"
            >
                {{ booking.type }}
            </p>
            <p
                class="text-muted-foreground"
                :class="{ 'line-through': booking.cancelled }"
            >
                {{ when }}
            </p>

            <p class="mt-4 text-sm text-muted-foreground">
                {{
                    booking.cancelled
                        ? copy.cancelled_body
                        : copy.confirmed_body.replace(':email', booking.email)
                }}
            </p>

            <div v-if="booking.cancelled" class="mt-8">
                <Button as-child>
                    <Link :href="index({ query: { lang: locale } }).url">
                        {{ copy.book_again }}
                    </Link>
                </Button>
            </div>

            <div v-else class="mt-8 space-y-3 border-t pt-6">
                <p class="text-sm text-muted-foreground">
                    {{ copy.cancel_hint }}
                </p>
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="cancel"
                >
                    {{ copy.cancel }}
                </Button>
            </div>
        </div>
    </div>
</template>
