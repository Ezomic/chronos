<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarPlus,
    List,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as calendar } from '@/routes/calendar';
import { edit as editCalendars } from '@/routes/calendars';

interface DashItem {
    key: string;
    title: string;
    color: string;
    all_day: boolean;
    starts_at: string;
    timezone: string;
    location: string | null;
}

const props = defineProps<{
    events: DashItem[];
    calendars: number;
    needs_reconnect: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const pad = (n: number) => String(n).padStart(2, '0');

const nowMs = Date.now();

// All-day events key off their stored UTC date; timed events off the viewer's
// local day, so buckets line up with the times shown.
const dayKey = (item: DashItem) => {
    if (item.all_day) {
        return item.starts_at.slice(0, 10);
    }

    const d = new Date(item.starts_at);

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const todayKey = (() => {
    const d = new Date();

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
})();

const today = computed(() =>
    props.events.filter((e) => dayKey(e) === todayKey),
);

const upcoming = computed(() =>
    props.events.filter((e) => dayKey(e) > todayKey).slice(0, 5),
);

const next = computed(
    () =>
        props.events.find((e) => new Date(e.starts_at).getTime() >= nowMs) ??
        null,
);

const thisWeek = computed(() => {
    const weekEnd = nowMs + 7 * 86_400_000;

    return props.events.filter((e) => {
        const t = new Date(e.starts_at).getTime();

        return t >= nowMs && t < weekEnd;
    }).length;
});

const greeting = computed(() => {
    const h = new Date().getHours();

    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
});

const todayLabel = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
}).format(new Date());

const time = (iso: string) => {
    const d = new Date(iso);

    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const date = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(iso));

const when = (item: DashItem) =>
    item.all_day
        ? `${date(item.starts_at)} · all day`
        : `${date(item.starts_at)}, ${time(item.starts_at)}`;

const agendaHref = calendar({ query: { view: 'agenda' } });
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">
                {{ greeting
                }}<template v-if="$page.props.auth.user"
                    >, {{ $page.props.auth.user.name }}</template
                >
            </h1>
            <p class="text-sm text-muted-foreground">{{ todayLabel }}</p>
        </div>

        <Link
            v-if="needs_reconnect"
            :href="editCalendars()"
            class="flex items-center gap-2 rounded-lg border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 hover:bg-amber-500/15 dark:text-amber-400"
        >
            <TriangleAlert class="size-4 shrink-0" />
            A connected calendar stopped syncing. Reconnect it in Calendar
            settings.
        </Link>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border p-5 lg:col-span-2">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Up next
                </p>
                <div v-if="next" class="mt-3 flex items-start gap-3">
                    <span
                        class="mt-1.5 size-3 shrink-0 rounded-full"
                        :style="{ backgroundColor: next.color }"
                    />
                    <div class="min-w-0">
                        <p class="text-lg leading-tight font-medium">
                            {{ next.title }}
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ when(next)
                            }}<template v-if="next.location">
                                · {{ next.location }}</template
                            >
                        </p>
                    </div>
                </div>
                <p v-else class="mt-3 text-muted-foreground">
                    Nothing coming up. Enjoy the quiet.
                </p>
            </div>

            <div class="flex flex-col gap-4">
                <div class="rounded-xl border p-5">
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ thisWeek }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ thisWeek === 1 ? 'event' : 'events' }} in the next 7
                        days
                    </p>
                </div>
                <div class="flex flex-col gap-2">
                    <Button as-child>
                        <Link :href="calendar()">
                            <CalendarPlus class="size-4" />
                            New event
                        </Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="agendaHref">
                            <List class="size-4" />
                            View agenda
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <div class="rounded-xl border p-5">
            <p
                class="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Today
            </p>
            <div v-if="today.length" class="space-y-1">
                <Link
                    v-for="item in today"
                    :key="item.key"
                    :href="calendar()"
                    class="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-accent"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: item.color }"
                    />
                    <span
                        class="w-16 shrink-0 text-sm tabular-nums text-muted-foreground"
                    >
                        {{ item.all_day ? 'All day' : time(item.starts_at) }}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium">
                        {{ item.title }}
                    </span>
                    <span
                        v-if="item.location"
                        class="hidden truncate text-xs text-muted-foreground sm:block"
                    >
                        {{ item.location }}
                    </span>
                </Link>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                No events today.
            </p>
        </div>

        <div v-if="upcoming.length" class="rounded-xl border p-5">
            <p
                class="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Coming up
            </p>
            <div class="space-y-1">
                <Link
                    v-for="item in upcoming"
                    :key="item.key"
                    :href="agendaHref"
                    class="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-accent"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: item.color }"
                    />
                    <span
                        class="w-28 shrink-0 text-sm tabular-nums text-muted-foreground"
                    >
                        {{ when(item) }}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium">
                        {{ item.title }}
                    </span>
                </Link>
            </div>
        </div>
    </div>
</template>

