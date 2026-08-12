import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        if (!data.action) {
            toast[data.type](data.message);

            return;
        }

        const action = data.action;

        toast[data.type](data.message, {
            // Long enough to notice and reach for, since this is the only way
            // back from a delete.
            duration: 10000,
            action: {
                label: action.label,
                onClick: () =>
                    router.post(
                        action.url,
                        {},
                        { preserveScroll: true, preserveState: false },
                    ),
            },
        });
    });
}
