<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bell,
    CalendarDays,
    Copy,
    Fingerprint,
    Plug,
    Search,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import { index as calendar } from '@/routes/calendar';

const features = [
    {
        icon: CalendarDays,
        title: 'Your own events',
        description:
            'Create, edit and organize events across your own calendars. They live on your server, not someone else’s.',
    },
    {
        icon: Plug,
        title: 'Connect Google & Microsoft',
        description:
            'Mirror your Google and Outlook calendars read-only, so everything you have is in one view.',
    },
    {
        icon: Bell,
        title: 'Reminders',
        description:
            'Email reminders before events, including repeating ones, so nothing slips through.',
    },
    {
        icon: Copy,
        title: 'Templates',
        description:
            'Save an event’s setup once and reuse it, then just pick the day and time.',
    },
    {
        icon: Search,
        title: 'Agenda & search',
        description:
            'Scan what’s coming in a clean agenda view and find any event instantly.',
    },
    {
        icon: Fingerprint,
        title: 'Passwordless login',
        description:
            'Sign in with a passkey, an email code or SSO. No passwords to manage.',
    },
];
</script>

<template>
    <Head title="Chronos — your self-hosted calendar">
        <meta
            name="description"
            content="Chronos is a private, self-hosted calendar: own your events, mirror Google and Microsoft, with reminders, templates and a fast agenda."
        />
    </Head>

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2">
                <span
                    class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-sm"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span class="text-lg font-semibold">Chronos</span>
            </div>

            <Button v-if="$page.props.auth.user" size="sm" as-child>
                <Link :href="calendar()">Open calendar</Link>
            </Button>
            <Button v-else variant="ghost" size="sm" as-child>
                <Link :href="login()">Log in</Link>
            </Button>
        </header>

        <main class="flex-1">
            <section class="mx-auto max-w-3xl px-6 py-20 text-center sm:py-28">
                <span
                    class="mx-auto mb-8 flex size-16 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-lg"
                >
                    <AppLogoIcon class="size-9" />
                </span>

                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Your calendar, self-hosted.
                </h1>
                <p
                    class="mx-auto mt-5 max-w-2xl text-lg text-pretty text-muted-foreground"
                >
                    Chronos keeps your own events and mirrors your Google and
                    Microsoft calendars in one private place you control, with
                    reminders, templates and a fast agenda.
                </p>

                <div class="mt-9 flex items-center justify-center gap-3">
                    <Button size="lg" as-child>
                        <Link
                            :href="$page.props.auth.user ? calendar() : login()"
                        >
                            {{
                                $page.props.auth.user
                                    ? 'Open calendar'
                                    : 'Get started'
                            }}
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </section>

            <section
                class="mx-auto grid max-w-5xl gap-4 px-6 pb-24 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border bg-card p-5 transition-colors hover:border-primary/40"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </span>
                    <h3 class="mt-4 font-medium">{{ feature.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.description }}
                    </p>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex w-full max-w-5xl flex-col items-center justify-between gap-2 px-6 py-6 text-sm text-muted-foreground sm:flex-row"
            >
                <div class="flex items-center gap-2">
                    <AppLogoIcon class="size-4 text-primary" />
                    <span>Chronos</span>
                </div>
                <span>Self-hosted calendar</span>
            </div>
        </footer>
    </div>
</template>
