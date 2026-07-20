<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Event $event) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->event->all_day
            ? $this->event->starts_at->timezone($this->event->timezone)->isoFormat('dddd D MMMM')
            : $this->event->starts_at->timezone($this->event->timezone)->isoFormat('dddd D MMMM, HH:mm');

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
