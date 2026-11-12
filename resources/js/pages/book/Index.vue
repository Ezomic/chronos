<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, Globe } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/book';
import type { BookingCopy, BookingLocale } from '@/types';

const props = defineProps<{
    locale: BookingLocale;
    copy: BookingCopy;
    type: { name: string; duration_minutes: number };
    slots: string[];
}>();

const detected = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const zones = computed(() => {
    const all = Intl.supportedValuesOf('timeZone');

    return all.includes(timezone.value) ? all : [timezone.value, ...all];
});

const timezone = ref(detected);
const pickedDay = ref<string | null>(null);
const pickedSlot = ref<string | null>(null);

const form = useForm({
    starts_at: '',
    name: '',
    email: '',
    phone: '',
    note: '',
    timezone: detected,
    website: '',
});

const dayKey = (iso: string): string =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone.value,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(iso));

const days = computed(() => {
    const grouped = new Map<string, string[]>();

    for (const slot of props.slots) {
        const key = dayKey(slot);
        grouped.set(key, [...(grouped.get(key) ?? []), slot]);
    }

    return [...grouped.entries()].map(([key, slots]) => ({ key, slots }));
});

const activeDay = computed(
    () =>
        days.value.find((day) => day.key === pickedDay.value) ?? days.value[0],
);

const format = (iso: string, options: Intl.DateTimeFormatOptions): string =>
    new Intl.DateTimeFormat(props.locale, {
        timeZone: timezone.value,
        ...options,
    }).format(new Date(iso));

const time = (iso: string) =>
    format(iso, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

const longDate = (iso: string) =>
    format(iso, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

function chooseTimezone(zone: string): void {
    timezone.value = zone;
    pickedDay.value = null;
    pickedSlot.value = null;
}

function submit(): void {
    if (!pickedSlot.value) {
        return;
    }

    form.starts_at = pickedSlot.value;
    form.timezone = timezone.value;
    form.post(store({ query: { lang: props.locale } }).url, {
        preserveScroll: true,
    });
}

function reloadSlots(): void {
    form.clearErrors();
    pickedSlot.value = null;
    router.reload({ only: ['slots'] });
}

const otherLanguage = computed(() => props.copy.language_code as BookingLocale);
</script>

<template>
    <Head :title="copy.heading" />

    <div class="min-h-screen bg-background px-4 py-8 sm:py-14">
        <div class="mx-auto w-full max-w-3xl">
            <div class="mb-6 flex items-center justify-between gap-4">
                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CalendarClock class="size-4" />
                    {{ type.name }}
                </div>
                <Link
                    :href="index({ query: { lang: otherLanguage } }).url"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {{ copy.language }}
                </Link>
            </div>

            <h1 class="text-2xl font-semibold tracking-tight">
                {{ copy.heading }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    copy.duration.replace(
                        ':minutes',
                        String(type.duration_minutes),
                    )
                }}.
                {{ copy.intro }}
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-2 text-sm">
                <Globe class="size-4 text-muted-foreground" />
                <label for="timezone" class="text-muted-foreground">
                    {{ copy.timezone }}
                </label>
                <select
                    id="timezone"
                    :value="timezone"
                    class="h-9 max-w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @change="
                        chooseTimezone(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="zone in zones" :key="zone" :value="zone">
                        {{ zone.replace(/_/g, ' ') }}
                    </option>
                </select>
            </div>

            <p
                v-if="!days.length"
                class="mt-8 rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                {{ copy.no_slots }}
            </p>

            <div v-else class="mt-8 grid gap-8 md:grid-cols-[1fr_1fr]">
                <section aria-labelledby="pick-day">
                    <h2 id="pick-day" class="mb-3 text-sm font-medium">
                        {{ copy.pick_day }}
                    </h2>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <button
                            v-for="day in days"
                            :key="day.key"
                            type="button"
                            class="rounded-md border px-2 py-2 text-center text-sm transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :class="
                                activeDay?.key === day.key
                                    ? 'border-primary bg-primary text-primary-foreground hover:bg-primary'
                                    : ''
                            "
                            :aria-label="longDate(day.slots[0])"
                            :aria-pressed="activeDay?.key === day.key"
                            @click="
                                pickedDay = day.key;
                                pickedSlot = null;
                            "
                        >
                            <span class="block text-xs opacity-80">
                                {{
                                    format(day.slots[0], {
                                        weekday: 'short',
                                    })
                                }}
                            </span>
                            <span class="block font-medium">
                                {{
                                    format(day.slots[0], {
                                        day: 'numeric',
                                        month: 'short',
                                    })
                                }}
                            </span>
                        </button>
                    </div>
                </section>

                <section v-if="activeDay" aria-labelledby="pick-time">
                    <h2 id="pick-time" class="text-sm font-medium">
                        {{ copy.pick_time }}
                    </h2>
                    <p class="mt-1 mb-3 text-sm text-muted-foreground">
                        {{ longDate(activeDay.slots[0]) }}
                    </p>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="slot in activeDay.slots"
                            :key="slot"
                            type="button"
                            class="rounded-md border px-2 py-2 text-sm tabular-nums transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :class="
                                pickedSlot === slot
                                    ? 'border-primary bg-primary text-primary-foreground hover:bg-primary'
                                    : ''
                            "
                            :aria-pressed="pickedSlot === slot"
                            @click="pickedSlot = slot"
                        >
                            {{ time(slot) }}
                        </button>
                    </div>
                </section>
            </div>

            <form
                v-if="pickedSlot"
                class="mt-10 max-w-md space-y-5 border-t pt-8"
                @submit.prevent="submit"
            >
                <div>
                    <h2 class="text-sm font-medium">{{ copy.details }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ copy.selected }}: {{ longDate(pickedSlot) }},
                        {{ time(pickedSlot) }}
                    </p>
                    <InputError class="mt-2" :message="form.errors.starts_at" />
                    <Button
                        v-if="form.errors.starts_at"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="reloadSlots"
                    >
                        {{ copy.reload }}
                    </Button>
                </div>

                <div class="grid gap-2">
                    <Label for="name">{{ copy.name }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        maxlength="100"
                        autocomplete="name"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ copy.email }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="254"
                        autocomplete="email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">{{ copy.phone }}</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        maxlength="30"
                        autocomplete="tel"
                    />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="note">{{ copy.note }}</Label>
                    <Input id="note" v-model="form.note" maxlength="300" />
                    <InputError :message="form.errors.note" />
                </div>

                <div
                    class="absolute -left-[9999px] size-px overflow-hidden"
                    aria-hidden="true"
                >
                    <label for="website">Website</label>
                    <input
                        id="website"
                        v-model="form.website"
                        type="text"
                        tabindex="-1"
                        autocomplete="off"
                    />
                </div>
                <InputError :message="form.errors.website" />
                <InputError :message="form.errors.timezone" />

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? copy.submitting : copy.submit }}
                </Button>
            </form>
        </div>
    </div>
</template>
