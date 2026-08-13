import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { store } from '@/routes/push-subscriptions';

/**
 * Subscribing a device to reminder notifications.
 *
 * The browser owns the subscription; Chronos only stores the endpoint and keys
 * it hands back, so the server can encrypt a payload to that device later.
 */
export function usePushNotifications(vapidPublicKey: string) {
    const busy = ref(false);
    const error = ref('');

    const supported = computed(
        () =>
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            vapidPublicKey !== '',
    );

    async function enable(): Promise<void> {
        error.value = '';

        if (!supported.value) {
            error.value = 'This browser cannot receive push notifications.';

            return;
        }

        busy.value = true;

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                error.value =
                    'Notifications are blocked for this site. Allow them in your browser settings first.';

                return;
            }

            const registration = await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                // Required by Chrome, and the only sane default: a push that
                // does not show a notification is not what anyone wants here.
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            const json = subscription.toJSON();

            router.post(
                store().url,
                {
                    endpoint: subscription.endpoint,
                    public_key: json.keys?.p256dh ?? '',
                    auth_token: json.keys?.auth ?? '',
                    device_label: describeDevice(),
                },
                { preserveScroll: true },
            );
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'Could not enable notifications.';
        } finally {
            busy.value = false;
        }
    }

    return { supported, busy, error, enable };
}

/** A label the user can recognise in the device list. */
function describeDevice(): string {
    const agent = navigator.userAgent;

    const browser = /Firefox\//.test(agent)
        ? 'Firefox'
        : /Edg\//.test(agent)
          ? 'Edge'
          : /Chrome\//.test(agent)
            ? 'Chrome'
            : /Safari\//.test(agent)
              ? 'Safari'
              : 'Browser';

    const platform = /iPhone|iPad/.test(agent)
        ? 'iOS'
        : /Android/.test(agent)
          ? 'Android'
          : /Macintosh/.test(agent)
            ? 'Mac'
            : /Windows/.test(agent)
              ? 'Windows'
              : /Linux/.test(agent)
                ? 'Linux'
                : 'device';

    return `${browser} on ${platform}`;
}

/**
 * The VAPID key travels as URL-safe base64 but subscribe() wants raw bytes.
 * Returned as the buffer itself, which is a BufferSource either way and side
 * steps the Uint8Array/SharedArrayBuffer variance in the DOM types.
 */
function urlBase64ToUint8Array(base64: string): ArrayBuffer {
    const padded = base64.padEnd(
        base64.length + ((4 - (base64.length % 4)) % 4),
        '=',
    );
    const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
    const buffer = new ArrayBuffer(binary.length);
    const bytes = new Uint8Array(buffer);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return buffer;
}
