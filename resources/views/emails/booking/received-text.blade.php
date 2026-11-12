{!! __('booking.mail.received.intro', ['name' => $name]) !!}

{!! $when !!}

{!! __('booking.mail.received.email') !!}: {!! $email !!}
@if ($phone)
{!! __('booking.mail.received.phone') !!}: {!! $phone !!}
@endif
@if ($note)
{!! __('booking.mail.received.note') !!}: {!! $note !!}
@endif

{!! __('booking.mail.received.manage') !!}: {!! $manageUrl !!}
