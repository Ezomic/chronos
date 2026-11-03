<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #17181A;">
    <p>{{ __('booking.mail.hello', ['name' => $name]) }}</p>
    <p>{{ __('booking.mail.confirmed.intro', ['type' => $type]) }}</p>
    <p style="font-size: 18px; font-weight: bold;">{{ $when }}</p>
    <p>{{ __('booking.mail.confirmed.attached') }}</p>
    <p><a href="{{ $manageUrl }}">{{ __('booking.mail.confirmed.manage') }}</a></p>
    <p style="color: #6b7280; font-size: 14px;">{{ __('booking.mail.confirmed.reschedule') }}</p>
</body>
</html>
