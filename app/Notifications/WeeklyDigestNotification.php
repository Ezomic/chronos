<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigestNotification extends Notification
{
    /**
     * @param  array<int, array{title: string, starts_at: CarbonInterface, all_day: bool, location: string|null, timezone: string}>  $items
     */
    public function __construct(
        private readonly CarbonInterface $weekStart,
        private readonly array $items,
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
        $range = $this->weekStart->isoFormat('D MMM')
            .' – '.$this->weekStart->addDays(6)->isoFormat('D MMM');

        $mail = (new MailMessage)
            ->subject('Your week: '.$range)
            ->greeting('Your week ahead')
            ->line('Here’s what’s on your calendar this week ('.$range.').');

        foreach ($this->items as $item) {
            $start = $item['starts_at']->timezone($item['timezone']);

            $when = $item['all_day']
                ? $start->isoFormat('ddd D MMM').' · all day'
                : $start->isoFormat('ddd D MMM, HH:mm');

            $line = $when.' — '.$item['title'];

            if ($item['location']) {
                $line .= ' ('.$item['location'].')';
            }

            $mail->line($line);
        }

        return $mail->action('Open calendar', url('/calendar'));
    }
}
