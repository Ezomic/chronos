<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #17181A;">
    <p>{{ __('booking.mail.cancelled.intro', ['name' => $name]) }}</p>
    <p style="font-size: 18px; font-weight: bold;">{{ $when }}</p>
    <p style="color: #6b7280; font-size: 14px;">{{ __('booking.mail.cancelled.freed') }}</p>
</body>
</html>
