<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #17181A;">
    <p>{{ __('booking.mail.received.intro', ['name' => $name]) }}</p>
    <p style="font-size: 18px; font-weight: bold;">{{ $when }}</p>
    <p>{{ __('booking.mail.received.email') }}: {{ $email }}</p>
    @if ($note)
        <p>{{ __('booking.mail.received.note') }}: {{ $note }}</p>
    @endif
    <p><a href="{{ $manageUrl }}">{{ __('booking.mail.received.manage') }}</a></p>
</body>
</html>
