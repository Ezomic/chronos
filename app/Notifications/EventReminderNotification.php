<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;
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
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
