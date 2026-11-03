<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { edit, update } from '@/routes/booking-settings';
import type { BookingDay } from '@/types';

const props = defineProps<{
    days: BookingDay[];
    timezone: string;
    duration_minutes: number;
    url: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Booking', href: edit() }],
    },
});

const weekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

const form = useForm({ days: props.days.map((day) => ({ ...day })) });

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}

const errorFor = (index: number): string | undefined =>
    form.errors[`days.${index}.end` as keyof typeof form.errors] ??
    form.errors[`days.${index}.start` as keyof typeof form.errors];
</script>

<template>
    <Head title="Booking" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Public booking page"
            :description="`Visitors can book a ${duration_minutes} minute intro call in these hours (${timezone.replace(/_/g, ' ')}). Anything on your calendar that is not marked free blocks the time.`"
        />

        <p class="text-sm">
            Share
            <a
                :href="url"
                class="underline underline-offset-4"
                target="_blank"
                >{{ url }}</a
            >
            (add <code>?lang=nl</code> or <code>?lang=en</code> to pick the
            language).
        </p>

        <form class="space-y-3" @submit.prevent="submit">
            <div
                v-for="(day, index) in form.days"
                :key="day.weekday"
                class="flex flex-wrap items-center gap-3"
            >
                <label class="flex w-36 items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="day.enabled"
                        :aria-label="weekdays[index]"
                        @update:model-value="day.enabled = $event === true"
                    />
                    {{ weekdays[index] }}
                </label>
                <Input
                    v-model="day.start"
                    type="time"
                    class="w-32"
                    :disabled="!day.enabled"
                    :aria-label="`${weekdays[index]} opens`"
                />
                <span class="text-sm text-muted-foreground">to</span>
                <Input
                    v-model="day.end"
                    type="time"
                    class="w-32"
                    :disabled="!day.enabled"
                    :aria-label="`${weekdays[index]} closes`"
                />
                <InputError class="w-full" :message="errorFor(index)" />
            </div>

            <InputError :message="form.errors.days" />

            <Button type="submit" :disabled="form.processing"
                >Save hours</Button
            >
            <span
                v-if="form.recentlySuccessful"
                class="ml-3 text-sm text-muted-foreground"
            >
                Saved.
            </span>
        </form>
    </div>
</template>
