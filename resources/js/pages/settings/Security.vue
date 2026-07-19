<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import { edit } from '@/routes/security';

const props = defineProps<ManagePasskeysProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <ManagePasskeys
        :canManagePasskeys="props.canManagePasskeys"
        :passkeys="props.passkeys"
    />
</template>
