<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! $notifiable instanceof User) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $auth = config('webpush.vapid');

        if (! is_array($auth) || ! is_string($auth['publicKey'] ?? null) || $auth['publicKey'] === '') {
            Log::warning('Web push skipped: no VAPID keys configured.');

            return;
        }

        try {
            $push = new WebPush(['VAPID' => $auth]);
        } catch (Throwable $e) {
            Log::error('Web push could not start.', ['message' => $e->getMessage()]);

            return;
        }

        $payload = json_encode($notification->toWebPush($notifiable));

        foreach ($subscriptions as $subscription) {
            $push->queueNotification($this->toSubscription($subscription), $payload === false ? null : $payload);
        }

        $this->flush($push, $subscriptions->keyBy('endpoint'));
    }

    private function toSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => 'aes128gcm',
        ]);
    }

    /**
     * Send the queue and act on what each endpoint said.
     *
     * A subscription the browser has thrown away answers 404 or 410 and will
     * never work again, so it is removed rather than retried forever. Same
     * lesson as CHRON-43, one layer out.
     *
     * @param  Collection<string, PushSubscription>  $byEndpoint
     */
    private function flush(WebPush $push, Collection $byEndpoint): void
    {
        foreach ($push->flush() as $report) {
            // The library's generator is untyped, so this is what makes the
            // report a report rather than something to hope about.
            if (! $report instanceof MessageSentReport) {
                continue;
            }

            $subscription = $byEndpoint->get((string) $report->getRequest()->getUri());

            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();

                continue;
            }

            Log::warning('Web push delivery failed.', [
                'subscription_id' => $subscription->id,
                'reason' => $report->getReason(),
            ]);
        }
    }
}
