<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param  CarbonInterface|null  $occurrenceStart  the specific occurrence
     *                                                 being reminded (recurring events); null uses the event's own start.
     */
    public function __construct(
        private readonly Event $event,
        private readonly ?CarbonInterface $occurrenceStart = null,
    ) {}

    /**
     * Push where the user has a device listening, mail otherwise. Never both:
     * one reminder should arrive once.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $hasDevice = $notifiable instanceof User
            && $notifiable->pushSubscriptions()->exists();

        return $hasDevice ? [WebPushChannel::class] : ['mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        $start = ($this->occurrenceStart ?? $this->event->starts_at)
            ->timezone($this->event->timezone);

        return [
            'title' => $this->event->title,
            'body' => $this->event->all_day
                ? $start->isoFormat('dddd D MMMM')
                : $start->isoFormat('dddd D MMMM, HH:mm'),
            'url' => route('calendar.index', [
                'view' => 'day',
                'date' => $start->toDateString(),
            ]),
            'tag' => 'event-'.$this->event->id.'-'.$start->format('YmdHi'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = ($this->occurrenceStart ?? $this->event->starts_at)
            ->timezone($this->event->timezone);

        $when = $this->event->all_day
            ? $start->isoFormat('dddd D MMMM')
            : $start->isoFormat('dddd D MMMM, HH:mm');

        $message = (new MailMessage)
            ->subject('Reminder: '.$this->event->title)
            ->greeting('Upcoming event')
            ->line($this->event->title)
            ->line($when);

        if ($this->event->location) {
            $message->line('Location: '.$this->event->location);
        }

        return $message->action('Open calendar', url('/calendar'));
    }
}
